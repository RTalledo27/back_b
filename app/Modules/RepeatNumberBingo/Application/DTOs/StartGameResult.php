<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

use Carbon\CarbonImmutable;

final readonly class StartGameResult
{
    public function __construct(
        public string $gameId,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $scheduledStartAt,
        public int $confirmedEntriesCount,
        public StartGameOutcome $outcome,
    ) {}
}
