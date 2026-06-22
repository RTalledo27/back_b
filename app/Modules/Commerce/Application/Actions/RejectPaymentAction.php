<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\RejectPaymentData;
use App\Modules\Commerce\Application\DTOs\RejectPaymentResult;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Exceptions\InvalidPaymentTransition;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Admin rejects a payment.
 *
 * Canonical lock order:
 *   1. Order
 *   2. Payment
 *   3. OrderItems  (sorted by id)
 *   4. NumberReservations  (sorted by id)
 *   5. GameNumbers  (sorted by id)
 *
 * Idempotency (state-based):
 *  - already Rejected → return existing result, `wasTransitionApplied = false`.
 *  - already Approved → InvalidPaymentTransition.
 *  - Pending / Cancelled / Refunded → InvalidPaymentTransition.
 *  - UnderReview → execute the rejection, `wasTransitionApplied = true`.
 *
 * Per Phase 2 spec: once rejected the order is terminal. The buyer must
 * create a new reservation to retry.
 *
 * Reconstruction on the "already Rejected" branch reads only operational
 * tables (OrderItems → GameNumbers). The PaymentRejected audit row is
 * NOT consulted — auditoría no es una dependencia operativa.
 */
final class RejectPaymentAction
{
    public function execute(RejectPaymentData $data): RejectPaymentResult
    {
        return DB::transaction(
            fn (): RejectPaymentResult => $this->executeWithinTransaction($data),
        );
    }

    public function executeWithinTransaction(RejectPaymentData $data): RejectPaymentResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'RejectPaymentAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        $orderId = Payment::query()->whereKey($data->paymentId)->value('order_id');

        if ($orderId === null) {
            throw (new ModelNotFoundException)->setModel(Payment::class, [$data->paymentId]);
        }

        // 1. Order
        /** @var Order $order */
        $order = Order::query()
            ->whereKey($orderId)
            ->lockForUpdate()
            ->firstOrFail();

        // 2. Payment
        /** @var Payment $payment */
        $payment = Payment::query()
            ->whereKey($data->paymentId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($payment->status === PaymentStatus::Rejected) {
            return $this->buildResultFromOperationalState($payment, $order, wasTransitionApplied: false);
        }

        if ($payment->status !== PaymentStatus::UnderReview) {
            throw InvalidPaymentTransition::from($payment->status, PaymentStatus::Rejected);
        }

        // 3. OrderItems (lock for consistency even though we don't mutate them).
        OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 4. NumberReservations
        /** @var Collection<int, NumberReservation> $reservations */
        $reservations = NumberReservation::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $gameNumberIds = $reservations->pluck('game_number_id')->sort()->values()->all();

        // 5. GameNumbers
        /** @var Collection<int, GameNumber> $gameNumbers */
        $gameNumbers = GameNumber::query()
            ->whereIn('id', $gameNumberIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $payment->transitionTo(PaymentStatus::Rejected);
        $payment->reviewed_by = $data->reviewerUserId;
        $payment->reviewed_at = now();
        $payment->rejection_reason = $data->reason;
        $payment->save();

        $order->transitionTo(OrderStatus::Rejected);
        $order->save();

        $releasedNumbers = [];
        foreach ($gameNumbers as $gn) {
            $releasedNumbers[] = (int) $gn->number;
            $gn->transitionTo(GameNumberStatus::Available);
            $gn->save();
        }
        sort($releasedNumbers);

        foreach ($reservations as $r) {
            $r->delete();
        }

        GameEvent::create([
            'game_id' => $order->game_id,
            'type' => GameEventType::PaymentRejected,
            'payload' => [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'reviewer_user_id' => $data->reviewerUserId,
                'buyer_user_id' => $order->user_id,
                'reason' => $data->reason,
                'released_game_number_ids' => $gameNumberIds,
                'released_numbers' => $releasedNumbers,
            ],
            'actor_user_id' => $data->reviewerUserId,
            'occurred_at' => now(),
        ]);

        return new RejectPaymentResult(
            paymentId: $payment->id,
            orderId: $order->id,
            gameId: $order->game_id,
            buyerUserId: $order->user_id,
            reviewerUserId: $data->reviewerUserId,
            orderStatus: $order->status->value,
            paymentStatus: $payment->status->value,
            reviewedAt: $payment->reviewed_at->toIso8601String(),
            reason: $data->reason,
            releasedGameNumberIds: $gameNumberIds,
            releasedNumbers: $releasedNumbers,
            wasTransitionApplied: true,
        );
    }

    /**
     * Reconstruct from operational tables only. The reservations were
     * deleted by the original rejection, but OrderItems still carry the
     * game_number_id of every line in the order, and the GameNumbers (now
     * back to Available) still hold the integer `number` column.
     */
    private function buildResultFromOperationalState(
        Payment $payment,
        Order $order,
        bool $wasTransitionApplied,
    ): RejectPaymentResult {
        $gameNumberIds = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->pluck('game_number_id')
            ->sort()
            ->values()
            ->all();

        $releasedNumbers = GameNumber::query()
            ->whereIn('id', $gameNumberIds)
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($n): int => (int) $n)
            ->values()
            ->all();

        return new RejectPaymentResult(
            paymentId: $payment->id,
            orderId: $order->id,
            gameId: $order->game_id,
            buyerUserId: $order->user_id,
            reviewerUserId: (int) $payment->reviewed_by,
            orderStatus: $order->status->value,
            paymentStatus: $payment->status->value,
            reviewedAt: $payment->reviewed_at?->toIso8601String() ?? '',
            reason: (string) ($payment->rejection_reason ?? ''),
            releasedGameNumberIds: $gameNumberIds,
            releasedNumbers: $releasedNumbers,
            wasTransitionApplied: $wasTransitionApplied,
        );
    }
}
