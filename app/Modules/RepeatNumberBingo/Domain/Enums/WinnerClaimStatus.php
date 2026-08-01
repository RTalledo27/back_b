<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Enums;

enum WinnerClaimStatus: string
{
    case PendingClaim = 'pending_claim';
    case IdentityPending = 'identity_pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::PendingClaim => in_array($next, [self::IdentityPending, self::Expired], true),
            self::IdentityPending => in_array($next, [self::Verified, self::Rejected], true),
            self::Verified, self::Rejected, self::Expired => false,
        };
    }
}
