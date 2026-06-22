<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Contracts;

use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameNotReadyForStart;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameStartReadiness;

/**
 * Port that StartGameAction uses to confirm there is no outstanding sales
 * activity for a game before allowing the transition to Running. The
 * concrete implementation lives in the consuming module that owns those
 * sales tables (inversion of dependencies).
 *
 * The implementation MUST:
 *   - require an active DB transaction (will assert it);
 *   - assume the Game row is already locked (FOR UPDATE) by the caller;
 *   - NOT open its own transaction;
 *   - throw GameNotReadyForStart with a list of reasons when any pre-flight
 *     condition fails — never return a "skip" or null-object answer.
 */
interface GameStartReadinessChecker
{
    /**
     * @throws GameNotReadyForStart one or more reasons, never empty.
     */
    public function assertReadyForStart(string $gameId): GameStartReadiness;
}
