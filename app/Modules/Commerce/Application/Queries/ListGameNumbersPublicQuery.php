<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\Eloquent\Collection;

/**
 * Public view of a game's numbers. Returns ONLY `{id, number, status}` —
 * never holder identity, relations or commerce-sensitive ids.
 */
final class ListGameNumbersPublicQuery
{
    /**
     * @return Collection<int, GameNumber>|null null when the game does not exist or is private (draft/cancelled).
     */
    public function forGameSlug(string $slug): ?Collection
    {
        /** @var ?Game $game */
        $game = Game::query()
            ->where('slug', $slug)
            ->whereNotIn('status', [GameStatus::Draft, GameStatus::Cancelled])
            ->first(['id', 'slug', 'status']);

        if ($game === null) {
            return null;
        }

        return GameNumber::query()
            ->where('game_id', $game->id)
            ->orderBy('number')
            ->get(['id', 'game_id', 'number', 'status']);
    }
}
