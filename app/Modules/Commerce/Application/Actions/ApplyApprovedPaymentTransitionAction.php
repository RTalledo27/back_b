<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\ApplyApprovedPaymentTransitionData;
use App\Modules\Commerce\Application\DTOs\ApplyApprovedPaymentTransitionResult;
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
use App\Modules\Shared\Application\Actions\RecordOutboxEventAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ApplyApprovedPaymentTransitionAction
{
    public function __construct(private readonly RecordOutboxEventAction $recordOutbox) {}

    public function executeWithinTransaction(
        ApplyApprovedPaymentTransitionData $data,
    ): ApplyApprovedPaymentTransitionResult {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'ApplyApprovedPaymentTransitionAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        $orderId = Payment::query()->whereKey($data->paymentId)->value('order_id');

        if ($orderId === null) {
            throw (new ModelNotFoundException)->setModel(Payment::class, [$data->paymentId]);
        }

        $gameId = Order::query()->whereKey($orderId)->value('game_id');

        if ($gameId === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$orderId]);
        }

        /** @var Game $game */
        $game = Game::query()
            ->whereKey($gameId)
            ->lockForUpdate()
            ->firstOrFail();

        /** @var Order $order */
        $order = Order::query()
            ->whereKey($orderId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($order->game_id !== $game->id) {
            throw new LogicException('Order/Game relationship changed under lock.');
        }

        /** @var Payment $payment */
        $payment = Payment::query()
            ->whereKey($data->paymentId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($payment->order_id !== $order->id) {
            throw new LogicException('Payment/Order relationship changed under lock.');
        }

        if ($payment->status === PaymentStatus::Approved) {
            return $this->buildResultFromOperationalState($payment, $order, wasTransitionApplied: false);
        }

        if ($data->isGateway()) {
            if ($payment->status !== PaymentStatus::Pending) {
                throw InvalidPaymentTransition::from($payment->status, PaymentStatus::Approved);
            }

            if ($order->status !== OrderStatus::Pending) {
                throw InvalidPaymentTransition::from($payment->status, PaymentStatus::Approved);
            }
        } elseif ($payment->status !== PaymentStatus::UnderReview) {
            throw InvalidPaymentTransition::from($payment->status, PaymentStatus::Approved);
        }

        $allowedStatuses = [GameStatus::SalesOpen, GameStatus::SalesClosed];
        if (! in_array($game->status, $allowedStatuses, true)) {
            throw new GameNotAcceptingPayments($game->id, $game->status, $allowedStatuses);
        }

        $items = OrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var Collection<int, NumberReservation> $reservations */
        $reservations = NumberReservation::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $gameNumberIds = $items->pluck('game_number_id')->sort()->values()->all();

        /** @var Collection<int, GameNumber> $gameNumbers */
        $gameNumbers = GameNumber::query()
            ->whereIn('id', $gameNumberIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($data->isGateway()) {
            $payment->approveFromGateway();
            $order->markPaidFromGateway();
        } else {
            $payment->transitionTo(PaymentStatus::Approved);
            $order->transitionTo(OrderStatus::Paid);
        }

        $reviewedAt = now();
        $payment->reviewed_by = $data->reviewerUserId;
        $payment->reviewed_at = $reviewedAt;
        $payment->save();

        $paidAt = now();
        $order->paid_at = $paidAt;
        $order->save();

        $gameNumbersById = $gameNumbers->keyBy('id');
        $entryIds = [];
        $allocationIds = [];
        $numbers = [];

        foreach ($gameNumbers as $gameNumber) {
            $gameNumber->transitionTo(GameNumberStatus::Sold);
            $gameNumber->save();
        }

        foreach ($items as $item) {
            /** @var GameNumber $gameNumber */
            $gameNumber = $gameNumbersById[$item->game_number_id];
            $numbers[] = (int) $gameNumber->number;

            $entry = GameEntry::create([
                'game_id' => $order->game_id,
                'game_number_id' => $gameNumber->id,
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

        foreach ($reservations as $reservation) {
            $reservation->delete();
        }

        sort($numbers);

        $paymentApprovedPayload = [
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'buyer_user_id' => $order->user_id,
            'game_entry_ids' => $entryIds,
            'notes' => $data->notes,
            'origin' => $data->origin,
        ];

        if ($data->reviewerUserId !== null) {
            $paymentApprovedPayload['reviewer_user_id'] = $data->reviewerUserId;
        }

        GameEvent::create([
            'game_id' => $order->game_id,
            'type' => GameEventType::PaymentApproved,
            'payload' => $paymentApprovedPayload,
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

        $this->recordOutbox->execute(
            eventType: 'payment_approved',
            aggregateType: 'payment',
            payload: [
                'schema_version' => 1,
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'game_id' => $order->game_id,
                'buyer_user_id' => $order->user_id,
                'occurred_at' => now()->toIso8601String(),
            ],
            aggregateId: $payment->id,
            deduplicationKey: 'payment_approved:'.$payment->id,
        );

        return new ApplyApprovedPaymentTransitionResult(
            paymentId: $payment->id,
            orderId: $order->id,
            gameId: $order->game_id,
            buyerUserId: $order->user_id,
            reviewerUserId: $payment->reviewed_by,
            orderStatus: $order->status->value,
            paymentStatus: $payment->status->value,
            paidAt: $paidAt->toIso8601String(),
            reviewedAt: $reviewedAt->toIso8601String(),
            gameEntryIds: $entryIds,
            purchaseAllocationIds: $allocationIds,
            gameNumberIds: $gameNumberIds,
            numbers: $numbers,
            wasTransitionApplied: true,
        );
    }

    private function buildResultFromOperationalState(
        Payment $payment,
        Order $order,
        bool $wasTransitionApplied,
    ): ApplyApprovedPaymentTransitionResult {
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
            ->map(fn ($number): int => (int) $number)
            ->values()
            ->all();

        return new ApplyApprovedPaymentTransitionResult(
            paymentId: $payment->id,
            orderId: $order->id,
            gameId: $order->game_id,
            buyerUserId: $order->user_id,
            reviewerUserId: $payment->reviewed_by,
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
