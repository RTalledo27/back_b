<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Public listing only shows games beyond the draft stage. Cancelled and
 * draft games are kept private.
 *
 * Visibility rule: GameStatus::publiclyVisible() — single source of truth.
 */
final class ListPublicGamesQuery
{
    /**
     * @return LengthAwarePaginator<int, Game>
     */
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Game::query()
            ->whereIn('status', GameStatus::publiclyVisible())
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
