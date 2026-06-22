<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by RebuildGameNumberCountersAction AFTER the rebuild transaction
 * commits AND only when the outcome was a fresh `Rebuilt`. The
 * `AlreadyConsistent` outcome does NOT dispatch.
 *
 * Plain Dispatchable on purpose: the Action wraps dispatch in
 * try/catch+report so a failing listener cannot revert the already-
 * committed projection.
 */
final class GameCountersRebuilt
{
    use Dispatchable;

    public function __construct(
        public readonly string $gameId,
        public readonly int $actorUserId,
        public readonly int $previousRows,
        public readonly int $rebuiltRows,
        public readonly int $totalDraws,
        public readonly string $rebuiltAt,
    ) {}
}
