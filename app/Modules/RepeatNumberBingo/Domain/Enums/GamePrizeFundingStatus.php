<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Enums;

enum GamePrizeFundingStatus: string
{
    case LegacyUnverified = 'legacy_unverified';
    case Unfunded = 'unfunded';
    case Funded = 'funded';
    case Reserved = 'reserved';
    case Released = 'released';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::LegacyUnverified, self::Unfunded => $next === self::Funded,
            self::Funded => in_array($next, [self::Reserved, self::Released], true),
            self::Reserved => $next === self::Released,
            self::Released => false,
        };
    }
}
