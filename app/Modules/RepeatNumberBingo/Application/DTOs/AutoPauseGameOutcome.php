<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

enum AutoPauseGameOutcome: string
{
    case Paused = 'paused';
    case AlreadyPaused = 'already_paused';
    case NotApplicable = 'not_applicable';
}
