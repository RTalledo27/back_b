<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

use Carbon\CarbonImmutable;

final readonly class ExecuteScheduledGameDrawResult
{
    public function __construct(
        public string $gameId,
        public CarbonImmutable $scheduledAt,
        public ExecuteScheduledGameDrawOutcome $outcome,
        public ?DrawGameNumberResult $drawResult = null,
        public ?CarbonImmutable $lastConsumedTickAt = null,
        public ?CarbonImmutable $nextDrawAt = null,
        public int $skippedTicks = 0,
    ) {}
}
