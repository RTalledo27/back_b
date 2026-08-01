<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum WinnerPayoutStatus: string
{
    case LegacyRegistered = 'legacy_registered';
    case Draft = 'draft';
    case AwaitingApproval = 'awaiting_approval';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Draft => in_array($next, [self::AwaitingApproval, self::Cancelled], true),
            self::AwaitingApproval => in_array($next, [self::Approved, self::Draft, self::Cancelled], true),
            self::Approved => in_array($next, [self::Processing, self::Cancelled], true),
            self::Processing => in_array($next, [self::Paid, self::Failed], true),
            self::Failed => in_array($next, [self::Processing, self::Cancelled], true),
            self::LegacyRegistered, self::Paid, self::Cancelled => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::LegacyRegistered, self::Paid, self::Cancelled], true);
    }
}
