<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\WinnerPayout;

final class WinnerPayoutPolicy
{
    public function viewAny(User $user): bool { return $user->isAdmin(); }

    public function view(User $user, WinnerPayout $payout): bool { return $user->isAdmin(); }

    public function create(User $user): bool { return $user->isAdmin(); }

    public function update(User $user, WinnerPayout $payout): bool { return $user->isAdmin(); }

    public function submit(User $user, WinnerPayout $payout): bool { return $user->isAdmin(); }

    public function approve(User $user, WinnerPayout $payout): bool { return $user->isAdmin(); }

    public function reject(User $user, WinnerPayout $payout): bool { return $user->isAdmin(); }

    public function execute(User $user, WinnerPayout $payout): bool { return $user->isAdmin(); }

    public function cancel(User $user, WinnerPayout $payout): bool { return $user->isAdmin(); }

    public function viewDocument(User $user, WinnerPayout $payout): bool { return $user->isAdmin(); }

    public function reconcile(User $user, WinnerPayout $payout): bool { return $user->isAdmin(); }
}
