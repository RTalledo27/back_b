<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when the scheduled_start_at attribute is set or changed. This is NOT
 * a state transition — the game's status remains unchanged.
 */
final class GameScheduledStartSet implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $gameId,
        public readonly DateTimeImmutable $scheduledStartAt,
    ) {}
}
