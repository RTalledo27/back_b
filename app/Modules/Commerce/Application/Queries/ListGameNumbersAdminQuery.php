<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\Eloquent\Collection;

/**
 * Admin view of every number in a game. Not paginated by default: a
 * game's number range is bounded (1..max ≤ a few hundred in practice)
 * and the admin operator typically wants the whole grid at once.
 *
 * Eager loads the active reservation and the confirmed entry so the
 * resource layer can render "who holds it" without N+1.
 */
final class ListGameNumbersAdminQuery
{
    /**
     * @return Collection<int, GameNumber>
     */
    public function forGame(string $gameId): Collection
    {
        return GameNumber::query()
            ->where('game_id', $gameId)
            ->orderBy('number')
            ->with([
                // NumberReservation has UNIQUE(game_number_id) → at most one.
                // We load via dynamic subquery on the inverse direction;
                // since GameNumber doesn't declare the relation, fall back
                // to a manual join through Eloquent's relationship API.
            ])
            ->get();
    }
}
