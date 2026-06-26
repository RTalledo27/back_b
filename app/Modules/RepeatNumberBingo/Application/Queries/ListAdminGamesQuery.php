<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ListAdminGamesQuery
{
    /**
     * Admins see all statuses. Visibility concept:
     *   published=true  → status IN GameStatus::publiclyVisible()
     *                     (single source of truth shared with ListPublicGamesQuery;
     *                      using whereIn avoids silent inclusion of any future
     *                      status that is not explicitly public)
     *   published=false → status NOT IN GameStatus::publiclyVisible()
     *
     * @param  array{
     *   search?: string,
     *   status?: string,
     *   published?: bool,
     *   auto_draw_enabled?: bool,
     *   created_from?: string,
     *   created_to?: string,
     * }  $filters
     * @return LengthAwarePaginator<int, Game>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));

        $query = Game::query()
            ->withCount([
                'numbers as sold_count' => fn ($q) => $q->where('status', GameNumberStatus::Sold),
                'numbers as reserved_count' => fn ($q) => $q->where('status', GameNumberStatus::Reserved),
                'numbers as available_count' => fn ($q) => $q->where('status', GameNumberStatus::Available),
            ])
            ->addSelect([
                'draws_total' => GameDraw::selectRaw('COUNT(*)')
                    ->whereColumn('game_id', 'games.id'),
                'orders_pending_count' => DB::table('orders')
                    ->selectRaw('COUNT(*)')
                    ->where('status', 'pending')
                    ->whereColumn('game_id', 'games.id'),
                'payments_under_review_count' => DB::table('payments')
                    ->selectRaw('COUNT(*)')
                    ->where('payments.status', 'under_review')
                    ->whereIn('payments.order_id', DB::table('orders')->select('id')->whereColumn('game_id', 'games.id')),
                'entries_confirmed_count' => GameEntry::selectRaw('COUNT(*)')
                    ->where('status', EntryStatus::Confirmed)
                    ->whereColumn('game_id', 'games.id'),
            ]);

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('slug', 'ilike', $term);
            });
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('published', $filters)) {
            if ($filters['published']) {
                $query->whereIn('status', GameStatus::publiclyVisible());
            } else {
                $query->whereNotIn('status', GameStatus::publiclyVisible());
            }
        }

        if (array_key_exists('auto_draw_enabled', $filters)) {
            $query->where('auto_draw_enabled', $filters['auto_draw_enabled']);
        }

        if (isset($filters['created_from']) && $filters['created_from'] !== '') {
            $query->where('created_at', '>=', $filters['created_from']);
        }

        if (isset($filters['created_to']) && $filters['created_to'] !== '') {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
