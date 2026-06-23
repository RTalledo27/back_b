<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

enum ResumeGameOutcome: string
{
    case Resumed = 'resumed';
    case AlreadyRunning = 'already_running';

    public function wasTransitionApplied(): bool
    {
        return $this === self::Resumed;
    }
}
