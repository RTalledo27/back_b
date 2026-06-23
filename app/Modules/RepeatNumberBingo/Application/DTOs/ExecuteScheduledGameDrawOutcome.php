<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

enum ExecuteScheduledGameDrawOutcome: string
{
    case Executed = 'executed';
    case Replayed = 'replayed';
    case ObsoleteTick = 'obsolete_tick';
    case SkippedPaused = 'skipped_paused';
    case SkippedCompleted = 'skipped_completed';
    case SkippedDisabled = 'skipped_disabled';
}
