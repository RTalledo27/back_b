<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Database\Eloquent\Builder;

final class GetPublicGameDetailQuery
{
    public function bySlug(string $slug): ?Game
    {
        return $this->visibleGames()
            ->with([
                'latestDraw',
                'winner.gameNumber:id,number',
                'winner.draw:id,sequence',
            ])
            ->where('slug', $slug)
            ->first();
    }

    public function findVisibleBySlug(string $slug): ?Game
    {
        return $this->visibleGames()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * @return Builder<Game>
     */
    private function visibleGames(): Builder
    {
        return Game::query()
            ->whereNotIn('status', [GameStatus::Draft, GameStatus::Cancelled]);
    }
}
