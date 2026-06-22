<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by DrawGameNumberAction AFTER the winning transaction commits.
 * Replays do NOT dispatch.
 *
 * Plain Dispatchable. Listener exceptions are reported individually so a
 * failed listener cannot block earlier or later dispatches, and never
 * roll back the persisted completion.
 */
final class GameCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $gameId,
        public readonly string $winningDrawId,
        public readonly string $completedAt,
    ) {}
}
