<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

enum RebuildCountersOutcome: string
{
    case Rebuilt = 'rebuilt';
    case AlreadyConsistent = 'already_consistent';

    public function wasTransitionApplied(): bool
    {
        return $this === self::Rebuilt;
    }
}
