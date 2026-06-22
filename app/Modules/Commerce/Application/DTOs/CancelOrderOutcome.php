<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

enum CancelOrderOutcome: string
{
    case Cancelled = 'cancelled';
    case AlreadyCancelled = 'already_cancelled';

    public function wasTransitionApplied(): bool
    {
        return $this === self::Cancelled;
    }
}
