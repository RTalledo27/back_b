<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

use Carbon\CarbonImmutable;

final readonly class RebuildCountersResult
{
    public function __construct(
        public string $gameId,
        public int $previousRows,
        public int $previousHitsTotal,
        public int $rebuiltRows,
        public int $rebuiltHitsTotal,
        public int $totalDraws,
        public int $maxSequence,
        public CarbonImmutable $rebuiltAt,
        public RebuildCountersOutcome $outcome,
    ) {}
}
