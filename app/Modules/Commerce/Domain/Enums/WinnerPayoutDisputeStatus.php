<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum WinnerPayoutDisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Open => $next === self::UnderReview,
            self::UnderReview => $next === self::Resolved,
            self::Resolved, self::Cancelled => false,
        };
    }
}
