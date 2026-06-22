<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Snapshot of the engine-relevant readiness facts at a point in time.
 * Carried by GameStartReadinessChecker::assertReadyForStart() when the
 * checks pass. CarbonImmutable so the timestamp cannot be mutated after
 * the check has been recorded.
 */
final readonly class GameStartReadiness
{
    public function __construct(
        public int $confirmedEntriesCount,
        public CarbonImmutable $verifiedAt,
    ) {}
}
