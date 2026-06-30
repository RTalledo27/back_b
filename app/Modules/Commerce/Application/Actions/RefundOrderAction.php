<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\RefundOrderData;
use App\Modules\Commerce\Application\DTOs\RefundOrderResult;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderRefunded;
use App\Modules\Commerce\Domain\Exceptions\IdempotencyKeyMismatch;
use App\Modules\Commerce\Domain\Exceptions\OrderNotRefundable;
use App\Modules\Commerce\Domain\Exceptions\RefundAmountMismatch;
use App\Modules\Commerce\Domain\Exceptions\WinnerEntryNotRefundable;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\Commerce\Domain\Models\Refund;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\Shared\Application\Actions\RecordOutboxEventAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Admin initiates a full refund for a paid order.
 *
 * Canonical lock order:
 *   1. Game           FOR UPDATE  — prevents lifecycle transitions during refund
 *   2. Order          FOR UPDATE  — root of the refund
 *   3. Refund         FOR UPDATE  — early-return idempotency check (by order_id)
 *   4. Payment        FOR UPDATE
 *   5. GameEntries    FOR UPDATE  (sorted by id)
 *   6. GameNumbers    FOR UPDATE  (sorted by id)
 *
 * The Refund early-return check (step 3) happens BEFORE locking Payment,
 * GameEntries and GameNumbers, so that retries do not fail because those
 * records are already in their terminal Refunded/Available states.
 *
 * Idempotency (state-based, table-level):
 *  - Same order_id + same idempotency_key_hash + same fingerprint
 *    → return existing result (wasAlreadyRefunded=true).
 *  - Same order_id + same idempotency_key_hash + different fingerprint
 *    → throw IdempotencyKeyMismatch (idempotency_conflict).
 *  - Same order_id + different idempotency_key_hash (another caller refunded)
 *    → return existing result (wasAlreadyRefunded=true).
 *
 * UNIQUE(idempotency_key_hash) and UNIQUE(order_id) on the refunds table
 * act as the final defense layer.
 *
 * @throws OrderNotRefundable
 * @throws WinnerEntryNotRefundable
 * @throws RefundAmountMismatch
 * @throws IdempotencyKeyMismatch
 */
final class RefundOrderAction
{
    public function __construct(private readonly RecordOutboxEventAction $recordOutbox) {}

    /** @var list<GameStatus> */
    private const ALLOWED_GAME_STATUSES = [
        GameStatus::SalesOpen,
        GameStatus::SalesClosed,
        GameStatus::Cancelled,
    ];

    public function execute(RefundOrderData $data): RefundOrderResult
    {
        // Pre-lock: resolve game_id without locking (immutable FK on Order).
        $gameId = Order::query()->whereKey($data->orderId)->value('game_id');

        if ($gameId === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$data->orderId]);
        }

        $result = DB::transaction(
            fn (): RefundOrderResult => $this->executeWithinTransaction($data, (string) $gameId),
        );

        if (! $result->wasAlreadyRefunded) {
            try {
                OrderRefunded::dispatch(
                    $result->refundId,
                    $result->orderId,
                    $result->paymentId,
                    $result->gameId,
                    $result->buyerUserId,
                    $result->actorUserId,
                    $result->refundedCents,
                    $result->currency,
                    $result->reason,
                    $result->gameEntryIds,
                    $result->gameNumberIds,
                    $result->numbers,
                    $result->processedAt,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    public function executeWithinTransaction(RefundOrderData $data, string $gameId): RefundOrderResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'RefundOrderAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        // ── Step 1: Game FOR UPDATE ───────────────────────────────────────────
        /** @var Game $game */
        $game = Game::query()
            ->whereKey($gameId)
            ->lockForUpdate()
            ->firstOrFail();

        // ── Step 2: Order FOR UPDATE ──────────────────────────────────────────
        /** @var Order $order */
        $order = Order::query()
            ->whereKey($data->orderId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->game_id !== $game->id) {
            throw new LogicException('Order/Game relationship changed under lock.');
        }

        // ── Step 3: Refund early-return (idempotency check) ───────────────────
        /** @var ?Refund $existing */
        $existing = Refund::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $this->resolveExistingRefund($existing, $data);
        }

        // ── Step 4: Payment FOR UPDATE ────────────────────────────────────────
        /** @var ?Payment $payment */
        $payment = Payment::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();

        // ── Invariant checks ──────────────────────────────────────────────────

        if ($order->status !== OrderStatus::Paid) {
            throw OrderNotRefundable::orderNotPaid($order->id, $order->status->value);
        }

        if ($payment === null) {
            throw OrderNotRefundable::paymentMissing($order->id);
        }

        if ($payment->status !== PaymentStatus::Approved) {
            throw OrderNotRefundable::paymentNotApproved($payment->id, $payment->status->value);
        }

        if ($payment->amount_cents !== $order->total_cents || $payment->currency !== $order->currency) {
            throw RefundAmountMismatch::centsOrCurrencyMismatch(
                $payment->amount_cents,
                $payment->currency,
                $order->total_cents,
                $order->currency,
            );
        }

        if (! in_array($game->status, self::ALLOWED_GAME_STATUSES, true)) {
            throw OrderNotRefundable::gameNotRefundable($game->id, $game->status->value);
        }

        // ── Step 5: GameEntries FOR UPDATE ────────────────────────────────────
        $allocations = PurchaseAllocation::query()
            ->whereIn(
                'order_item_id',
                OrderItem::query()->where('order_id', $order->id)->pluck('id'),
            )
            ->get();

        if ($allocations->isEmpty()) {
            throw OrderNotRefundable::noAllocationsFound($order->id);
        }

        $entryIds = $allocations->pluck('game_entry_id')->sort()->values()->all();

        /** @var Collection<int, GameEntry> $entries */
        $entries = GameEntry::query()
            ->whereIn('id', $entryIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // Validate all entries are Confirmed and none is Winner.
        foreach ($entries as $entry) {
            if ($entry->status === EntryStatus::Winner) {
                throw WinnerEntryNotRefundable::entryIsWinner($entry->id);
            }

            if ($entry->status !== EntryStatus::Confirmed) {
                throw OrderNotRefundable::entryNotConfirmed($entry->id, $entry->status->value);
            }
        }

        // Check no GameWinner references any of these entries.
        $winnerReferences = GameWinner::query()
            ->whereIn('game_entry_id', $entryIds)
            ->exists();

        if ($winnerReferences) {
            throw WinnerEntryNotRefundable::gameWinnerReferencesOrderEntry($order->id);
        }

        // ── Step 6: GameNumbers FOR UPDATE ────────────────────────────────────
        $gameNumberIds = $allocations->map(function (PurchaseAllocation $alloc): string {
            /** @var OrderItem $item */
            $item = OrderItem::query()->whereKey($alloc->order_item_id)->first();

            return (string) $item->game_number_id;
        })->sort()->values()->all();

        /** @var Collection<int, GameNumber> $gameNumbers */
        $gameNumbers = GameNumber::query()
            ->whereIn('id', $gameNumberIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // ── Mutations ─────────────────────────────────────────────────────────
        $processedAt = now();

        $payment->transitionTo(PaymentStatus::Refunded);
        $payment->save();

        $order->transitionTo(OrderStatus::Refunded);
        $order->save();

        $numbers = [];
        foreach ($gameNumbers as $gn) {
            $numbers[] = (int) $gn->number;
            $gn->transitionTo(GameNumberStatus::Available);
            $gn->save();
        }
        sort($numbers);

        foreach ($entries as $entry) {
            $entry->transitionTo(EntryStatus::Refunded);
            $entry->save();
        }

        // ── INSERT Refund ──────────────────────────────────────────────────────
        $refund = Refund::create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'amount_cents' => $order->total_cents,
            'currency' => $order->currency,
            'reason' => $data->reason,
            'idempotency_key_hash' => $data->idempotencyKeyHash,
            'request_fingerprint' => $data->requestFingerprint,
            'processed_by_user_id' => $data->actorUserId,
            'processed_at' => $processedAt,
            'created_at' => $processedAt,
        ]);

        // ── Audit event (inside transaction) ─────────────────────────────────
        GameEvent::create([
            'game_id' => $order->game_id,
            'type' => GameEventType::OrderRefunded,
            'payload' => [
                'refund_id' => $refund->id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'buyer_user_id' => $order->user_id,
                'actor_user_id' => $data->actorUserId,
                'refunded_cents' => $order->total_cents,
                'currency' => $order->currency,
                'refund_reason' => $data->reason,
            ],
            'actor_user_id' => $data->actorUserId,
            'occurred_at' => $processedAt,
        ]);

        $this->recordOutbox->execute(
            eventType: 'order_refunded',
            aggregateType: 'order',
            payload: [
                'schema_version' => 1,
                'refund_id' => $refund->id,
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'game_id' => $order->game_id,
                'buyer_user_id' => $order->user_id,
                'occurred_at' => $processedAt->toIso8601String(),
            ],
            aggregateId: $order->id,
            deduplicationKey: 'order_refunded:'.$order->id,
        );

        return new RefundOrderResult(
            refundId: $refund->id,
            orderId: $order->id,
            paymentId: $payment->id,
            gameId: $order->game_id,
            buyerUserId: $order->user_id,
            actorUserId: $data->actorUserId,
            refundedCents: $refund->amount_cents,
            currency: $refund->currency,
            reason: $refund->reason,
            processedAt: $refund->processed_at->toIso8601String(),
            createdAt: $refund->created_at->toIso8601String(),
            gameEntryIds: array_values($entryIds),
            gameNumberIds: array_values($gameNumberIds),
            numbers: $numbers,
            wasAlreadyRefunded: false,
        );
    }

    private function resolveExistingRefund(Refund $existing, RefundOrderData $data): RefundOrderResult
    {
        // Same key hash: check fingerprint for exact replay vs conflict.
        if (hash_equals($existing->idempotency_key_hash, $data->idempotencyKeyHash)) {
            if (! hash_equals($existing->request_fingerprint, $data->requestFingerprint)) {
                throw IdempotencyKeyMismatch::forKey($data->idempotencyKeyHash);
            }
        }
        // Different key hash (order already refunded by another request) → return existing.

        return $this->buildResultFromExistingRefund($existing);
    }

    private function buildResultFromExistingRefund(Refund $existing): RefundOrderResult
    {
        $allocations = PurchaseAllocation::query()
            ->whereIn(
                'order_item_id',
                OrderItem::query()->where('order_id', $existing->order_id)->pluck('id'),
            )
            ->get();

        $entryIds = $allocations->pluck('game_entry_id')->sort()->values()->all();

        $gameNumberIds = $allocations->map(function (PurchaseAllocation $alloc): string {
            return (string) OrderItem::query()->whereKey($alloc->order_item_id)->value('game_number_id');
        })->sort()->values()->all();

        $numbers = GameNumber::query()
            ->whereIn('id', $gameNumberIds)
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($n): int => (int) $n)
            ->values()
            ->all();

        $order = Order::query()->whereKey($existing->order_id)->firstOrFail();

        return new RefundOrderResult(
            refundId: $existing->id,
            orderId: $existing->order_id,
            paymentId: $existing->payment_id,
            gameId: $order->game_id,
            buyerUserId: $order->user_id,
            actorUserId: $existing->processed_by_user_id,
            refundedCents: $existing->amount_cents,
            currency: $existing->currency,
            reason: $existing->reason,
            processedAt: $existing->processed_at->toIso8601String(),
            createdAt: $existing->created_at->toIso8601String(),
            gameEntryIds: array_values($entryIds),
            gameNumberIds: array_values($gameNumberIds),
            numbers: $numbers,
            wasAlreadyRefunded: true,
        );
    }
}
