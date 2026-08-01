<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Enums;

enum WinnerClaimEventType: string
{
    case ClaimCreated = 'claim_created';
    case ClaimSubmitted = 'claim_submitted';
    case IdentityVerified = 'identity_verified';
    case IdentityRejected = 'identity_rejected';
    case ClaimExpired = 'claim_expired';
    case LegacyClaimInitialized = 'legacy_claim_initialized';
}
