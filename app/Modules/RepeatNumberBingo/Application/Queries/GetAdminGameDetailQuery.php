<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Support\Facades\DB;

final class GetAdminGameDetailQuery
{
    public function byId(string $gameId): ?Game
    {
        $game = Game::query()
            ->with([
                'latestDraw',
                'winner.gameNumber:id,number',
                'winner.draw:id,sequence',
            ])
            ->withCount([
                'numbers as sold_count' => fn ($q) => $q->where('status', GameNumberStatus::Sold),
                'numbers as reserved_count' => fn ($q) => $q->where('status', GameNumberStatus::Reserved),
                'numbers as available_count' => fn ($q) => $q->where('status', GameNumberStatus::Available),
            ])
            ->where('id', $gameId)
            ->first();

        if ($game === null) {
            return null;
        }

        $game->setAttribute('commerce', $this->computeCommerce($gameId));
        $game->setAttribute('projection', $this->computeProjection($gameId, $game));

        return $game;
    }

    /**
     * Commerce aggregates grouped by real enum status values.
     * Uses one query per category — no N+1 per record.
     *
     * @return array{
     *   reservations: array{total: int},
     *   orders: array<string, int>,
     *   payments: array<string, int>,
     *   entries: array<string, int>,
     * }
     */
    private function computeCommerce(string $gameId): array
    {
        $ordersRow = DB::table('orders')
            ->where('game_id', $gameId)
            ->selectRaw("
                COALESCE(COUNT(*) FILTER (WHERE status = 'pending'), 0) as pending,
                COALESCE(COUNT(*) FILTER (WHERE status = 'payment_submitted'), 0) as payment_submitted,
                COALESCE(COUNT(*) FILTER (WHERE status = 'paid'), 0) as paid,
                COALESCE(COUNT(*) FILTER (WHERE status = 'rejected'), 0) as rejected,
                COALESCE(COUNT(*) FILTER (WHERE status = 'expired'), 0) as expired,
                COALESCE(COUNT(*) FILTER (WHERE status = 'cancelled'), 0) as cancelled,
                COALESCE(COUNT(*) FILTER (WHERE status = 'refunded'), 0) as refunded
            ")
            ->first();

        $paymentsRow = DB::table('payments')
            ->join('orders', 'payments.order_id', '=', 'orders.id')
            ->where('orders.game_id', $gameId)
            ->selectRaw("
                COALESCE(COUNT(*) FILTER (WHERE payments.status = 'pending'), 0) as pending,
                COALESCE(COUNT(*) FILTER (WHERE payments.status = 'under_review'), 0) as under_review,
                COALESCE(COUNT(*) FILTER (WHERE payments.status = 'approved'), 0) as approved,
                COALESCE(COUNT(*) FILTER (WHERE payments.status = 'rejected'), 0) as rejected,
                COALESCE(COUNT(*) FILTER (WHERE payments.status = 'cancelled'), 0) as cancelled,
                COALESCE(COUNT(*) FILTER (WHERE payments.status = 'refunded'), 0) as refunded
            ")
            ->first();

        $entriesRow = DB::table('game_entries')
            ->where('game_id', $gameId)
            ->selectRaw("
                COALESCE(COUNT(*) FILTER (WHERE status = 'confirmed'), 0) as confirmed,
                COALESCE(COUNT(*) FILTER (WHERE status = 'cancelled'), 0) as cancelled,
                COALESCE(COUNT(*) FILTER (WHERE status = 'refunded'), 0) as refunded,
                COALESCE(COUNT(*) FILTER (WHERE status = 'winner'), 0) as winner
            ")
            ->first();

        $reservationsRow = DB::table('number_reservations')
            ->join('game_numbers', 'number_reservations.game_number_id', '=', 'game_numbers.id')
            ->where('game_numbers.game_id', $gameId)
            ->selectRaw('COUNT(*) as total')
            ->first();

        return [
            'reservations' => [
                'total' => (int) ($reservationsRow->total ?? 0),
            ],
            'orders' => [
                'pending' => (int) ($ordersRow->pending ?? 0),
                'payment_submitted' => (int) ($ordersRow->payment_submitted ?? 0),
                'paid' => (int) ($ordersRow->paid ?? 0),
                'rejected' => (int) ($ordersRow->rejected ?? 0),
                'expired' => (int) ($ordersRow->expired ?? 0),
                'cancelled' => (int) ($ordersRow->cancelled ?? 0),
                'refunded' => (int) ($ordersRow->refunded ?? 0),
            ],
            'payments' => [
                'pending' => (int) ($paymentsRow->pending ?? 0),
                'under_review' => (int) ($paymentsRow->under_review ?? 0),
                'approved' => (int) ($paymentsRow->approved ?? 0),
                'rejected' => (int) ($paymentsRow->rejected ?? 0),
                'cancelled' => (int) ($paymentsRow->cancelled ?? 0),
                'refunded' => (int) ($paymentsRow->refunded ?? 0),
            ],
            'entries' => [
                'confirmed' => (int) ($entriesRow->confirmed ?? 0),
                'cancelled' => (int) ($entriesRow->cancelled ?? 0),
                'refunded' => (int) ($entriesRow->refunded ?? 0),
                'winner' => (int) ($entriesRow->winner ?? 0),
            ],
        ];
    }

    /**
     * Draw/counter projection: totals computed without loading collections.
     *
     * @return array{
     *   draws_total: int,
     *   distinct_drawn_numbers: int,
     *   max_counter_hits: int,
     *   last_drawn_number: int|null,
     * }
     */
    private function computeProjection(string $gameId, Game $game): array
    {
        $drawsRow = DB::table('game_draws')
            ->where('game_id', $gameId)
            ->selectRaw('COUNT(*) as total, COUNT(DISTINCT drawn_number) as distinct_numbers')
            ->first();

        $maxHits = DB::table('game_number_counters')
            ->where('game_id', $gameId)
            ->max('hits_count');

        return [
            'draws_total' => (int) ($drawsRow->total ?? 0),
            'distinct_drawn_numbers' => (int) ($drawsRow->distinct_numbers ?? 0),
            'max_counter_hits' => (int) ($maxHits ?? 0),
            'last_drawn_number' => $game->latestDraw?->drawn_number,
        ];
    }
}
