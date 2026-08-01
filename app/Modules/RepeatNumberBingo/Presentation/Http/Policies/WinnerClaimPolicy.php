<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;

final class WinnerClaimPolicy
{
    public function viewOwnWinnerClaim(User $user, WinnerClaim $claim): bool
    {
        return $claim->winner_user_id === $user->id;
    }

    public function submitWinnerClaim(User $user, GameWinner $winner): bool
    {
        return $winner->user_id === $user->id;
    }

    public function confirmReceipt(User $user, GameWinner $winner): bool
    {
        return ! $user->isAdmin() && $winner->user_id === $user->id;
    }

    public function openDispute(User $user, GameWinner $winner): bool
    {
        return ! $user->isAdmin() && $winner->user_id === $user->id;
    }

    public function viewWinnerClaims(User $user): bool
    {
        return $user->isAdmin();
    }

    public function reviewWinnerClaim(User $user, WinnerClaim $claim): bool
    {
        return $user->isAdmin();
    }

    public function viewWinnerIdentityDocuments(User $user, WinnerClaim $claim): bool
    {
        return $user->isAdmin() || $claim->winner_user_id === $user->id;
    }
}
