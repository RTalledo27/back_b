<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

use Carbon\CarbonImmutable;

final readonly class ResumeGameResult
{
    public function __construct(
        public string $gameId,
        public CarbonImmutable $resumedAt,
        public CarbonImmutable $nextDrawAt,
        public ResumeGameOutcome $outcome,
    ) {}
}
