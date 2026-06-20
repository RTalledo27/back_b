<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Public listing only shows games beyond the draft stage. Cancelled and
 * draft games are kept private.
 */
final class ListPublicGamesQuery
{
    /**
     * @return LengthAwarePaginator<int, Game>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Game::query()
            ->whereIn('status', [
                GameStatus::Published,
                GameStatus::SalesOpen,
                GameStatus::SalesClosed,
                GameStatus::Running,
                GameStatus::Paused,
                GameStatus::Resolving,
                GameStatus::Completed,
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
