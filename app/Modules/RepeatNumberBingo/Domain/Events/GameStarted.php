<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by StartGameAction AFTER the start transaction commits, and only
 * when the outcome was a fresh `Started`. Replays (`AlreadyStarted`) do
 * not dispatch.
 *
 * Plain Dispatchable: the Action wraps dispatch in a try/catch+report
 * block so a failing listener cannot roll back the already-committed
 * transition.
 *
 * Payload is the safe shape that downstream listeners may need — no
 * names, emails, prize amounts, payment ids, or document paths.
 */
final class GameStarted
{
    use Dispatchable;

    public function __construct(
        public readonly string $gameId,
        public readonly int $actorUserId,
        public readonly string $scheduledStartAt,
        public readonly string $startedAt,
        public readonly int $confirmedEntriesCount,
    ) {}
}
