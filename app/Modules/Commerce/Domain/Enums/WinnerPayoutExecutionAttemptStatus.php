<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum WinnerPayoutExecutionAttemptStatus: string
{
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Processing => in_array($next, [self::Paid, self::Failed], true),
            self::Paid, self::Failed => false,
        };
    }
}
