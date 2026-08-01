<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum WinnerPayoutReceiptStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case WindowExpired = 'window_expired';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Pending => in_array($next, [self::Confirmed, self::WindowExpired], true),
            self::Confirmed, self::WindowExpired => false,
        };
    }
}
