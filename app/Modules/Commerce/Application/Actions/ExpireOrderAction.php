<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\ExpireOrderData;
use App\Modules\Commerce\Application\DTOs\ExpireOrderOutcome;
use App\Modules\Commerce\Application\DTOs\ExpireOrderResult;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderReservationsExpired;
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
 * Atomic expiration for a single order.
 *
 * Lock order (canonical Commerce sequence):
 *   1. Order
 *   2. Payment
 *   3. OrderItems  (sorted by id)
 *   4. NumberReservations  (sorted by id)
 *   5. GameNumbers  (sorted by id)
 *
 * Outcomes:
 *   Expired              → fresh transition pending → expired;
 *                          numbers released, reservations deleted,
 *                          payment cancelled, single audit row, event
 *                          should be dispatched after commit.
 *   AlreadyExpired       → idempotent return, no audit, no event.
 *   SkippedStateChanged  → order moved to payment_submitted / paid /
 *                          rejected / cancelled / refunded after the
 *                          batch query — safe no-op.
 *   NotDue               → expires_at is NULL or in the future — safe
 *                          no-op.
 *
 * Integrity guard: if items / reservations / game_numbers don't agree
 * (missing reservation, mismatched ids, number not actually reserved),
 * throws OrderExpirationIntegrityError so the WHOLE transaction rolls
 * back. No partial release is ever possible.
 */
final class ExpireOrderAction
{
    /**
     * Public entry point. Opens its own transaction AND dispatches the
     * OrderReservationsExpired event after commit when the outcome is a
     * fresh `Expired`. Centralising the dispatch here ensures that every
     * caller (HTTP, batch job, future CLI) emits the event exactly once
     * per transition — without each caller re-implementing the rule.
     *
     * Listener exceptions are reported via report() and never roll back
     * the already-committed expiration.
     */
    public function execute(ExpireOrderData $data): ExpireOrderResult
    {
        $result = DB::transaction(
            fn (): ExpireOrderResult => $this->executeWithinTransaction($data),
        );

        if ($result->outcome === ExpireOrderOutcome::Expired) {
            try {
                OrderReservationsExpired::dispatch(
                    $result->orderId,
                    $result->paymentId,
                    $result->gameId,
                    $result->userId,
                    $result->gameNumberIds,
                    $result->numbers,
                    (string) $result->expiredAt,
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    public function executeWithinTransaction(ExpireOrderData $data): ExpireOrderResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'ExpireOrderAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        // 1. Order
        /** @var Order $order */
        $order = Order::query()
            ->whereKey($data->orderId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->status === OrderStatus::Expired) {
            return $this->skip($order, ExpireOrderOutcome::AlreadyExpired);
        }

        if ($order->status !== OrderStatus::Pending) {
            return $this->skip($order, ExpireOrderOutcome::SkippedStateChanged);
        }

        if ($order->expires_at === null || $order->expires_at->isFuture()) {
            return $this->skip($order, ExpireOrderOutcome::NotDue);
        }

        // 2. Payment (may be null in theory, but in our flow always exists)
        /** @var ?Payment $payment */
        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if ($payment !== null && $payment->status !== PaymentStatus::Pending) {
            // Payment escaped from pending between batch query and lock —
            // treat as state-changed, do not touch anything.
            return $this->skip($order, ExpireOrderOutcome::SkippedStateChanged);
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
                $order->id,
                $items->count(),
                $reservations->count(),
            );
        }

        $itemGameNumberIds = $items->pluck('game_number_id')->sort()->values()->all();
        $reservationGameNumberIds = $reservations->pluck('game_number_id')->sort()->values()->all();

        if ($itemGameNumberIds !== $reservationGameNumberIds) {
            $missing = array_values(array_diff($itemGameNumberIds, $reservationGameNumberIds));

            throw OrderExpirationIntegrityError::itemsAndReservationsDoNotMatch($order->id, $missing);
        }

        // 5. GameNumbers (deterministic order)
        /** @var Collection<int, GameNumber> $gameNumbers */
        $gameNumbers = GameNumber::query()
            ->whereIn('id', $itemGameNumberIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $notReserved = $gameNumbers
            ->filter(fn (GameNumber $gn): bool => $gn->status !== GameNumberStatus::Reserved)
            ->pluck('id')
            ->values()
            ->all();

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

        $originalExpiresAt = $order->expires_at->toIso8601String();
        $expiredAt = now();

        $order->transitionTo(OrderStatus::Expired);
        $order->expired_at = $expiredAt;
        // expires_at intentionally kept for traceability.
        $order->save();

        if ($payment !== null && $payment->status === PaymentStatus::Pending) {
            $payment->transitionTo(PaymentStatus::Cancelled);
            $payment->save();
        }

        GameEvent::create([
            'game_id' => $order->game_id,
            'type' => GameEventType::ReservationExpired,
            'payload' => [
                'order_id' => $order->id,
                'payment_id' => $payment?->id,
                'user_id' => $order->user_id,
                'game_number_ids' => $itemGameNumberIds,
                'numbers' => $releasedNumbers,
                'scheduled_expiration_at' => $originalExpiresAt,
                'expired_at' => $expiredAt->toIso8601String(),
            ],
            'actor_user_id' => null,
            'occurred_at' => $expiredAt,
        ]);

        return new ExpireOrderResult(
            orderId: $order->id,
            paymentId: $payment?->id,
            gameId: $order->game_id,
            userId: $order->user_id,
            gameNumberIds: $itemGameNumberIds,
            numbers: $releasedNumbers,
            expiredAt: $expiredAt->toIso8601String(),
            outcome: ExpireOrderOutcome::Expired,
        );
    }

    private function skip(Order $order, ExpireOrderOutcome $outcome): ExpireOrderResult
    {
        return new ExpireOrderResult(
            orderId: $order->id,
            paymentId: Payment::query()->where('order_id', $order->id)->value('id'),
            gameId: $order->game_id,
            userId: $order->user_id,
            gameNumberIds: [],
            numbers: [],
            expiredAt: $order->expired_at?->toIso8601String(),
            outcome: $outcome,
        );
    }
}
