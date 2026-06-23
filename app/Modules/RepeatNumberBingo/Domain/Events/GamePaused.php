<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by PauseGameAction AFTER the pause transaction commits, and only
 * when the outcome was a fresh `Paused`. Replays (`AlreadyPaused`) do not
 * dispatch.
 *
 * Plain Dispatchable: the Action wraps dispatch in try/catch+report so a
 * failing listener cannot roll back the already-committed transition.
 */
final class GamePaused
{
    use Dispatchable;

    public function __construct(
        public readonly string $gameId,
        public readonly int $actorUserId,
        public readonly string $pausedAt,
    ) {}
}
