<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by DrawGameNumberAction AFTER the draw transaction commits.
 * Replays (same command_id) do NOT dispatch.
 *
 * Plain Dispatchable: the Action wraps dispatch in try/catch+report so a
 * failing listener cannot roll back the already-committed draw/counter/
 * command.
 *
 * Payload is the safe shape — no Order, Payment, prize amount, document
 * or buyer personal info.
 */
final class GameNumberDrawn
{
    use Dispatchable;

    public function __construct(
        public readonly string $gameId,
        public readonly string $drawId,
        public readonly string $commandId,
        public readonly int $sequence,
        public readonly int $drawnNumber,
        public readonly int $currentHits,
        public readonly int $hitsRequired,
        public readonly bool $numberIsSold,
        public readonly string $drawnAt,
    ) {}
}
