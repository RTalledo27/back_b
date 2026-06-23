<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

enum PauseGameOutcome: string
{
    case Paused = 'paused';
    case AlreadyPaused = 'already_paused';

    public function wasTransitionApplied(): bool
    {
        return $this === self::Paused;
    }
}
