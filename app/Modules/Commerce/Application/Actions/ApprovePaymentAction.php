<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\ApprovePaymentData;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentResult;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Exceptions\GameNotAcceptingPayments;
use App\Modules\Commerce\Domain\Exceptions\InvalidPaymentTransition;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Admin approves a payment.
 *
 * Canonical lock order (Phase 3.3):
 *   1. Game           <-- engine-wide root lock, serialises with StartGame
 *   2. Order
 *   3. Payment
 *   4. OrderItems  (sorted by id)
 *   5. NumberReservations  (sorted by id)
 *   6. GameNumbers  (sorted by id)
 *
 * The caller supplies a payment_id. We resolve order_id and game_id
 * without taking any lock (cheap SELECTs on immutable foreign keys),
 * then acquire locks in the order above.
 *
 * Idempotency contract (state-based):
 *  - already Approved → return existing result, `wasTransitionApplied = false`.
 *  - already Rejected / Cancelled / Refunded → InvalidPaymentTransition.
 *  - Pending → InvalidPaymentTransition (no evidence yet).
 *  - UnderReview → execute the approval, `wasTransitionApplied = true`.
 *
 * Reconstruction on the "already Approved" branch reads only operational
 * tables (OrderItems → PurchaseAllocations → GameEntries → GameNumbers).
 * Auditoría no es una dependencia operativa.
 *
 * Game lifecycle gate (Phase 3.3):
 *  - A *new* approval (UnderReview → Approved) only proceeds when the
 *    game is in sales_open or sales_closed. Any other state raises
 *    GameNotAcceptingPayments. This invariant — combined with the Game
 *    FOR UPDATE acquired first — guarantees no number can be sold after
 *    the game has transitioned to running/paused/resolving/completed.
 *  - A *replay* over an already-approved payment reconstructs the result
 *    regardless of the game's current status. It does not produce a new
 *    sale, audit row or event, so a game that subsequently became running
 *    must NOT cause this branch to fail.
 */
final class ApprovePaymentAction
{
    public function execute(ApprovePaymentData $data): ApprovePaymentResult
    {
        return DB::transaction(
            fn (): ApprovePaymentResult => $this->executeWithinTransaction($data),
        );
    }

    public function executeWithinTransaction(ApprovePaymentData $data): ApprovePaymentResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'ApprovePaymentAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        // Resolve order_id and game_id without locking — payment.order_id
        // and order.game_id are immutable foreign keys, so a stale read
        // here is safe; the locks below revalidate the relationship.
        $orderId = Payment::query()->whereKey($data->paymentId)->value('order_id');

        if ($orderId === null) {
            throw (new ModelNotFoundException)->setModel(Payment::class, [$data->paymentId]);
        }

        $gameId = Order::query()->whereKey($orderId)->value('game_id');

        if ($gameId === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        // 1. Game — root lock for the whole engine. Serialises with
        //    StartGameAction; any approval started after Start finishes
        //    will see the new status and fail the gate below.
        /** @var Game $game */
        $game = Game::query()
            ->whereKey($gameId)
            ->lockForUpdate()
            ->firstOrFail();

        // 2. Order
        /** @var Order $order */
        $order = Order::query()
            ->whereKey($orderId)
            ->lockForUpdate()
            ->firstOrFail();

        // Defensive revalidation: the cheap pre-lock SELECTs trusted the
        // immutable FK relationships; reassert them once everything is
        // locked.
        if ($order->game_id !== $game->id) {
            throw new LogicException('Order/Game relationship changed under lock.');
        }

        // 3. Payment
        /** @var Payment $payment */
        $payment = Payment::query()
            ->whereKey($data->paymentId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($payment->order_id !== $order->id) {
            throw new LogicException('Payment/Order relationship changed under lock.');
        }

        // Already approved: idempotent replay — reconstruct from the
        // operational tables regardless of game.status. We do NOT mutate
        // anything and do NOT emit events on this branch.
        if ($payment->status === PaymentStatus::Approved) {
            return $this->buildResultFromOperationalState($payment, $order, wasTransitionApplied: false);
        }

        // Any non-UnderReview status (Pending / Rejected / Cancelled /
        // Refunded) is rejected exactly like in Phase 2 — these are not
        // "no-op" outcomes.
        if ($payment->status !== PaymentStatus::UnderReview) {
            throw InvalidPaymentTransition::from($payment->status, PaymentStatus::Approved);
        }

        // Engine gate: a new approval may only proceed while the game is
        // still accepting payment confirmations.
        $allowedStatuses = [GameStatus::SalesOpen, GameStatus::SalesClosed];
        if (! in_array($game->status, $allowedStatuses, true)) {
            throw new GameNotAcceptingPayments($game->id, $game->status, $allowedStatuses);
        }

        // 4. OrderItems
        $items = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 5. NumberReservations
        /** @var Collection<int, NumberReservation> $reservations */
        $reservations = NumberReservation::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $gameNumberIds = $items->pluck('game_number_id')->sort()->values()->all();

        // 6. GameNumbers
        /** @var Collection<int, GameNumber> $gameNumbers */
        $gameNumbers = GameNumber::query()
            ->whereIn('id', $gameNumberIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $gameNumbersById = $gameNumbers->keyBy('id');

        $payment->transitionTo(PaymentStatus::Approved);
        $payment->reviewed_by = $data->reviewerUserId;
        $payment->reviewed_at = now();
        $payment->save();

        $order->transitionTo(OrderStatus::Paid);
        $order->paid_at = now();
        $order->save();

        foreach ($gameNumbers as $gn) {
            $gn->transitionTo(GameNumberStatus::Sold);
            $gn->save();
        }

        $entryIds = [];
        $allocationIds = [];
        $numbers = [];

        foreach ($items as $item) {
            /** @var GameNumber $gn */
            $gn = $gameNumbersById[$item->game_number_id];
            $numbers[] = (int) $gn->number;

            $entry = GameEntry::create([
                'game_id' => $order->game_id,
                'game_number_id' => $gn->id,
                'user_id' => $order->user_id,
                'status' => EntryStatus::Confirmed,
                'confirmed_at' => now(),
            ]);
            $entryIds[] = $entry->id;

            $allocation = PurchaseAllocation::create([
                'order_item_id' => $item->id,
                'game_entry_id' => $entry->id,
                'payment_id' => $payment->id,
            ]);
            $allocationIds[] = $allocation->id;
        }

        foreach ($reservations as $r) {
            $r->delete();
        }

        sort($numbers);

        GameEvent::create([
            'game_id' => $order->game_id,
            'type' => GameEventType::PaymentApproved,
            'payload' => [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'reviewer_user_id' => $data->reviewerUserId,
                'buyer_user_id' => $order->user_id,
                'game_entry_ids' => $entryIds,
                'notes' => $data->notes,
            ],
            'actor_user_id' => $data->reviewerUserId,
            'occurred_at' => now(),
        ]);

        GameEvent::create([
            'game_id' => $order->game_id,
            'type' => GameEventType::NumberSold,
            'payload' => [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'game_number_ids' => $gameNumberIds,
                'numbers' => $numbers,
                'game_entry_ids' => $entryIds,
            ],
            'actor_user_id' => $data->reviewerUserId,
            'occurred_at' => now(),
        ]);

        return new ApprovePaymentResult(
            paymentId: $payment->id,
            orderId: $order->id,
            gameId: $order->game_id,
            buyerUserId: $order->user_id,
            reviewerUserId: $data->reviewerUserId,
            orderStatus: $order->status->value,
            paymentStatus: $payment->status->value,
            paidAt: $order->paid_at->toIso8601String(),
            reviewedAt: $payment->reviewed_at->toIso8601String(),
            gameEntryIds: $entryIds,
            purchaseAllocationIds: $allocationIds,
            gameNumberIds: $gameNumberIds,
            numbers: $numbers,
            wasTransitionApplied: true,
        );
    }

    /**
     * Reconstruct the Result for an already-approved payment from
     * operational tables only — game_events is NOT consulted.
     */
    private function buildResultFromOperationalState(
        Payment $payment,
        Order $order,
        bool $wasTransitionApplied,
    ): ApprovePaymentResult {
        $items = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $gameNumberIds = $items->pluck('game_number_id')->sort()->values()->all();

        /** @var Collection<int, PurchaseAllocation> $allocations */
        $allocations = PurchaseAllocation::query()
            ->whereIn('order_item_id', $items->pluck('id')->all())
            ->orderBy('id')
            ->get();

        $entryIds = $allocations->pluck('game_entry_id')->values()->all();
        $allocationIds = $allocations->pluck('id')->values()->all();

        $numbers = GameNumber::query()
            ->whereIn('id', $gameNumberIds)
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($n): int => (int) $n)
            ->values()
            ->all();

        return new ApprovePaymentResult(
            paymentId: $payment->id,
            orderId: $order->id,
            gameId: $order->game_id,
            buyerUserId: $order->user_id,
            reviewerUserId: (int) $payment->reviewed_by,
            orderStatus: $order->status->value,
            paymentStatus: $payment->status->value,
            paidAt: $order->paid_at?->toIso8601String() ?? '',
            reviewedAt: $payment->reviewed_at?->toIso8601String() ?? '',
            gameEntryIds: $entryIds,
            purchaseAllocationIds: $allocationIds,
            gameNumberIds: $gameNumberIds,
            numbers: $numbers,
            wasTransitionApplied: $wasTransitionApplied,
        );
    }
}
