<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case PaymentSubmitted = 'payment_submitted';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * Single source of truth for order state transitions.
     *
     * Note: payment_submitted does NOT transition to expired automatically.
     * Once evidence is attached, only an admin can resolve the order
     * (approve, reject, or explicit administrative cancel out of MVP scope).
     *
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Pending => [self::PaymentSubmitted, self::Expired, self::Cancelled],
            self::PaymentSubmitted => [self::Paid, self::Rejected, self::Cancelled],
            self::Paid => [self::Refunded],
            self::Rejected, self::Expired, self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNextStates(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNextStates() === [];
    }
}
