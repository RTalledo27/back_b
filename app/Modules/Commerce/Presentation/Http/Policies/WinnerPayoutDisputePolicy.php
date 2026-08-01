<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;

final class WinnerPayoutDisputePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, WinnerPayoutDispute $dispute): bool
    {
        return $user->isAdmin();
    }

    public function open(User $user, WinnerPayoutDispute $dispute): bool
    {
        return ! $user->isAdmin() && $dispute->winner_user_id === $user->id;
    }

    public function startReview(User $user, WinnerPayoutDispute $dispute): bool
    {
        return $user->isAdmin();
    }

    public function resolve(User $user, WinnerPayoutDispute $dispute): bool
    {
        return $user->isAdmin() && $dispute->winner_user_id !== $user->id;
    }
}
