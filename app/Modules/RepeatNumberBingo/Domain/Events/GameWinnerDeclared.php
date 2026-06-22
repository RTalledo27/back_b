<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by DrawGameNumberAction AFTER the winning transaction commits.
 * Replays do NOT dispatch.
 *
 * Plain Dispatchable. Listener exceptions are reported by the Action's
 * `dispatchSafely` helper and never roll back the persisted winner.
 *
 * Payload is intentionally narrow: no buyer name, email, phone, payment
 * id, prize amount or document reference.
 */
final class GameWinnerDeclared
{
    use Dispatchable;

    public function __construct(
        public readonly string $gameId,
        public readonly string $gameEntryId,
        public readonly string $gameDrawId,
        public readonly string $wonAt,
    ) {}
}
