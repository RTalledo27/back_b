<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\ReserveGameNumbersData;
use App\Modules\Commerce\Application\DTOs\ReserveGameNumbersResult;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\GameNumbersReserved;
use App\Modules\Commerce\Domain\Exceptions\GameNotInSalesOpen;
use App\Modules\Commerce\Domain\Exceptions\GameNumbersDoNotBelongToGame;
use App\Modules\Commerce\Domain\Exceptions\NumberNotAvailableForReservation;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ReserveGameNumbersAction
{
    /**
     * Public entry point. Opens its own DB transaction. Use this for
     * internal callers (CLI, seeders, future jobs). The idempotent HTTP
     * path uses executeWithinTransaction() so the executor controls the
     * transaction boundary alongside the idempotency_keys write.
     */
    public function execute(ReserveGameNumbersData $data): ReserveGameNumbersResult
    {
        return DB::transaction(fn (): ReserveGameNumbersResult => $this->executeWithinTransaction($data));
    }

    public function executeWithinTransaction(ReserveGameNumbersData $data): ReserveGameNumbersResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'ReserveGameNumbersAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        // 1. Lock the Game row (canonical first lock for Commerce flows that
        //    depend on game state or pricing).
        /** @var Game $game */
        $game = Game::query()
            ->whereKey($data->gameId)
            ->lockForUpdate()
            ->firstOrFail();

        // 2. Revalidate sales open under lock.
        if ($game->status !== GameStatus::SalesOpen) {
            throw GameNotInSalesOpen::from($game->status);
        }

        // 3. Snapshot price and currency from the locked game.
        $unitPriceCents = $game->ticket_price_cents;
        $currency = $game->currency;

        // 4. Deterministic id ordering — same lock order across all callers,
        //    same hash basis on idempotency replay.
        $sortedIds = $this->sortedIds($data->gameNumberIds);

        // 5. Lock all requested game_numbers in id order.
        /** @var Collection<int, GameNumber> $gameNumbers */
        $gameNumbers = GameNumber::query()
            ->whereIn('id', $sortedIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // 6 + 7. Existence + ownership: every requested id must exist AND
        //         belong to the routed game.
        if ($gameNumbers->count() !== count($sortedIds)) {
            $foundIds = $gameNumbers->pluck('id')->all();
            $missing = array_values(array_diff($sortedIds, $foundIds));

            throw GameNumbersDoNotBelongToGame::offendingIds($data->gameId, $missing);
        }

        $foreign = $gameNumbers->filter(fn (GameNumber $gn): bool => $gn->game_id !== $data->gameId)
            ->pluck('id')
            ->values()
            ->all();

        if ($foreign !== []) {
            throw GameNumbersDoNotBelongToGame::offendingIds($data->gameId, $foreign);
        }

        // 8. Availability under lock.
        $unavailable = $gameNumbers->filter(
            fn (GameNumber $gn): bool => $gn->status !== GameNumberStatus::Available,
        )->pluck('id')->values()->all();

        if ($unavailable !== []) {
            throw NumberNotAvailableForReservation::forIds($unavailable);
        }

        // 9. Compute totals on the server — never trust client.
        $count = $gameNumbers->count();
        $subtotalCents = $unitPriceCents * $count;
        $totalCents = $subtotalCents;

        // 10. Create the order.
        $ttlMinutes = (int) config('commerce.reservation.ttl_minutes', 10);

        /** @var Order $order */
        $order = Order::create([
            'user_id' => $data->userId,
            'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => $subtotalCents,
            'total_cents' => $totalCents,
            'currency' => $currency,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        // 11. Order items (snapshot of unit price at reservation time).
        foreach ($gameNumbers as $gn) {
            OrderItem::create([
                'order_id' => $order->id,
                'game_number_id' => $gn->id,
                'unit_price_cents' => $unitPriceCents,
            ]);
        }

        // 12. Number reservations (UNIQUE(game_number_id) is the ultimate
        //     guarantee against concurrent double-hold).
        $reservationIds = [];
        foreach ($gameNumbers as $gn) {
            $reservation = NumberReservation::create([
                'order_id' => $order->id,
                'game_number_id' => $gn->id,
            ]);
            $reservationIds[] = $reservation->id;
        }

        // 13. Pending payment.
        /** @var Payment $payment */
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => $totalCents,
            'currency' => $currency,
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);

        // 14. Transition each game number via the domain method.
        foreach ($gameNumbers as $gn) {
            $gn->transitionTo(GameNumberStatus::Reserved);
            $gn->save();
        }

        // 15. Aggregated audit event — one row per reservation, lists all
        //     numbers (per user's preferred shape).
        $numbersList = $gameNumbers->pluck('number')->map(fn ($n): int => (int) $n)
            ->sort()->values()->all();

        GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::NumberReserved,
            'payload' => [
                'order_id' => $order->id,
                'user_id' => $data->userId,
                'game_number_ids' => $sortedIds,
                'numbers' => $numbersList,
                'expires_at' => $order->expires_at->toIso8601String(),
            ],
            'actor_user_id' => $data->userId,
            'occurred_at' => now(),
        ]);

        $result = new ReserveGameNumbersResult(
            orderId: $order->id,
            gameId: $game->id,
            userId: $data->userId,
            paymentId: $payment->id,
            numbers: $numbersList,
            gameNumberIds: $sortedIds,
            reservationIds: $reservationIds,
            subtotalCents: $subtotalCents,
            totalCents: $totalCents,
            currency: $currency,
            expiresAt: $order->expires_at->toIso8601String(),
        );

        // 16. Domain Event — ShouldDispatchAfterCommit defers dispatch until
        //     the outer transaction commits. On idempotent replay the Action
        //     is not invoked, so this event is correctly NOT redispatched.
        GameNumbersReserved::dispatch(
            $result->orderId,
            $result->gameId,
            $result->userId,
            $result->gameNumberIds,
            $result->numbers,
            $result->expiresAt,
        );

        return $result;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function sortedIds(array $ids): array
    {
        $sorted = $ids;
        sort($sorted, SORT_STRING);

        return array_values($sorted);
    }
}
