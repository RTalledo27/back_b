<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\Payment;

/**
 * Authorisation only. Payment state and business invariants live inside
 * ApprovePaymentAction / RejectPaymentAction, enforced AFTER locks.
 */
final class PaymentPolicy
{
    public function approve(User $user, Payment $payment): bool
    {
        return $user->isAdmin();
    }

    public function reject(User $user, Payment $payment): bool
    {
        return $user->isAdmin();
    }

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->isAdmin();
    }

    public function downloadDocument(User $user, Payment $payment): bool
    {
        return $user->isAdmin();
    }
}
