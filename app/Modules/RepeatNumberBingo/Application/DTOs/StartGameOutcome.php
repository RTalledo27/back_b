<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

enum StartGameOutcome: string
{
    case Started = 'started';
    case AlreadyStarted = 'already_started';

    public function wasTransitionApplied(): bool
    {
        return $this === self::Started;
    }
}
