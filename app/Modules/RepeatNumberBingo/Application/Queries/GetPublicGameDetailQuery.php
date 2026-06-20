<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;

final class GetPublicGameDetailQuery
{
    public function bySlug(string $slug): ?Game
    {
        return Game::query()
            ->where('slug', $slug)
            ->whereNotIn('status', [GameStatus::Draft, GameStatus::Cancelled])
            ->first();
    }
}
