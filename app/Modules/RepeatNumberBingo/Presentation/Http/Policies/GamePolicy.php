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
}
