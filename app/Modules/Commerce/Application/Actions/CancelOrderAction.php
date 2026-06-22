<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\CancelOrderData;
use App\Modules\Commerce\Application\DTOs\CancelOrderOutcome;
use App\Modules\Commerce\Application\DTOs\CancelOrderResult;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderCancelledByUser;
use App\Modules\Commerce\Domain\Exceptions\InvalidOrderTransition;
use App\Modules\Commerce\Domain\Exceptions\OrderExpirationIntegrityError;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Player cancels their own pending order.
 *
 * Lock order (canonical Commerce sequence):
 *   1. Order
 *   2. Payment
 *   3. OrderItems  (sorted by id)
 *   4. NumberReservations  (sorted by id)
 *   5. GameNumbers  (sorted by id)
 *
 * Idempotency (state-based):
 *  - already Cancelled by this user → return existing result, no audit,
 *    no event.
 *  - any other terminal state (paid, rejected, expired, refunded) or
 *    payment_submitted → InvalidOrderTransition (mapped to 422).
 *  - pending → execute the cancellation.
 *
 * Dispatch is centralised inside execute() — same pattern as
 * ExpireOrderAction.
 */
final class CancelOrderAction
{
    public function execute(CancelOrderData $data): CancelOrderResult
    {
        $result = DB::transaction(
            fn (): CancelOrderResult => $this->executeWithinTransaction($data),
        );

        if ($result->outcome === CancelOrderOutcome::Cancelled) {
            try {
                OrderCancelledByUser::dispatch(
                    $result->orderId,
                    $result->paymentId,
                    $result->gameId,
                    $result->userId,
                    $result->gameNumberIds,
                    $result->numbers,
                    (string) $result->cancelledAt,
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    public function executeWithinTransaction(CancelOrderData $data): CancelOrderResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'CancelOrderAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        // 1. Order
        /** @var Order $order */
        $order = Order::query()
            ->whereKey($data->orderId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->user_id !== $data->userId) {
            // Treat as not-found from the caller perspective. The Policy
            // already runs at the HTTP layer; this is defence in depth.
            throw InvalidOrderTransition::from($order->status, OrderStatus::Cancelled);
        }

        if ($order->status === OrderStatus::Cancelled) {
            return $this->buildResultFromOperationalState($order, CancelOrderOutcome::AlreadyCancelled);
        }

        if ($order->status !== OrderStatus::Pending) {
            throw InvalidOrderTransition::from($order->status, OrderStatus::Cancelled);
        }

        // 2. Payment
        /** @var ?Payment $payment */
        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if ($payment !== null && $payment->status !== PaymentStatus::Pending) {
            throw InvalidOrderTransition::from($order->status, OrderStatus::Cancelled);
        }

        // 3. OrderItems
        $items = OrderItem::query()
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

        if ($items->count() !== $reservations->count()) {
            throw OrderExpirationIntegrityError::reservationCountMismatch(
                $order->id, $items->count(), $reservations->count(),
            );
        }

        $itemIds = $items->pluck('game_number_id')->sort()->values()->all();
        $reservationIds = $reservations->pluck('game_number_id')->sort()->values()->all();

        if ($itemIds !== $reservationIds) {
            $missing = array_values(array_diff($itemIds, $reservationIds));
            throw OrderExpirationIntegrityError::itemsAndReservationsDoNotMatch($order->id, $missing);
        }

        // 5. GameNumbers
        /** @var Collection<int, GameNumber> $gameNumbers */
        $gameNumbers = GameNumber::query()
            ->whereIn('id', $itemIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $notReserved = $gameNumbers
            ->filter(fn (GameNumber $gn): bool => $gn->status !== GameNumberStatus::Reserved)
            ->pluck('id')->values()->all();

        if ($notReserved !== []) {
            throw OrderExpirationIntegrityError::numbersNotReserved($order->id, $notReserved);
        }

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

        $cancelledAt = now();
        $order->transitionTo(OrderStatus::Cancelled);
        $order->cancelled_at = $cancelledAt;
        $order->save();

        if ($payment !== null) {
            $payment->transitionTo(PaymentStatus::Cancelled);
            $payment->save();
        }

        GameEvent::create([
            'game_id' => $order->game_id,
            'type' => GameEventType::GameCancelled, // user-initiated order cancellation maps to existing enum
            'payload' => [
                'event_subtype' => 'order_cancelled_by_user',
                'order_id' => $order->id,
                'payment_id' => $payment?->id,
                'user_id' => $order->user_id,
                'game_number_ids' => $itemIds,
                'numbers' => $releasedNumbers,
                'cancelled_at' => $cancelledAt->toIso8601String(),
            ],
            'actor_user_id' => $data->userId,
            'occurred_at' => $cancelledAt,
        ]);

        return new CancelOrderResult(
            orderId: $order->id,
            paymentId: $payment?->id,
            gameId: $order->game_id,
            userId: $order->user_id,
            gameNumberIds: $itemIds,
            numbers: $releasedNumbers,
            cancelledAt: $cancelledAt->toIso8601String(),
            outcome: CancelOrderOutcome::Cancelled,
        );
    }

    private function buildResultFromOperationalState(Order $order, CancelOrderOutcome $outcome): CancelOrderResult
    {
        $itemIds = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->pluck('game_number_id')
            ->sort()
            ->values()
            ->all();

        $numbers = GameNumber::query()
            ->whereIn('id', $itemIds)
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($n): int => (int) $n)
            ->values()
            ->all();

        $paymentId = Payment::query()->where('order_id', $order->id)->value('id');

        return new CancelOrderResult(
            orderId: $order->id,
            paymentId: $paymentId,
            gameId: $order->game_id,
            userId: $order->user_id,
            gameNumberIds: $itemIds,
            numbers: $numbers,
            cancelledAt: $order->cancelled_at?->toIso8601String(),
            outcome: $outcome,
        );
    }
}
