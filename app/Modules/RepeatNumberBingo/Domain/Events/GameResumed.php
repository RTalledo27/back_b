<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by ResumeGameAction AFTER the resume transaction commits, and
 * only when the outcome was a fresh `Resumed`. Replays (`AlreadyRunning`)
 * do not dispatch.
 */
final class GameResumed
{
    use Dispatchable;

    public function __construct(
        public readonly string $gameId,
        public readonly int $actorUserId,
        public readonly string $resumedAt,
        public readonly string $nextDrawAt,
    ) {}
}
