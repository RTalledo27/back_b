<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;

/**
 * Authorization layer. Domain transition rules live in GameStatus and Game::transitionTo;
 * this Policy only answers "is the actor allowed to attempt this admin operation?".
 */
final class GamePolicy
{
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function publish(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function openSales(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function closeSales(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function schedule(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    /**
     * Player intent to reserve numbers in this game. Only checks identity —
     * sales-open, availability, ownership and expiration are domain rules
     * enforced by ReserveGameNumbersAction after acquiring locks.
     */
    public function reserve(User $user, Game $game): bool
    {
        return true;
    }

    public function start(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function pause(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function resume(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function draw(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function rebuildCounters(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function viewDraws(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function viewCounters(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }

    public function viewWinner(User $user, Game $game): bool
    {
        return $user->isAdmin();
    }
}
