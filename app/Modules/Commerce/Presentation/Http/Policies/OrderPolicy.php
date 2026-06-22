<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\Order;

/**
 * Authorisation only — never validates business state. Order/payment
 * state, expiration and reservation existence are enforced inside
 * SubmitPaymentEvidenceAction after acquiring locks.
 */
final class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id || $user->isAdmin();
    }

    public function submitEvidence(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }

    public function cancel(User $user, Order $order): bool
    {
        return $order->user_id === $user->id || $user->isAdmin();
    }
}
