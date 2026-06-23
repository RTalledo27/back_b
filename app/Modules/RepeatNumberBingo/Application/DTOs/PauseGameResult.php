<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

use Carbon\CarbonImmutable;

final readonly class PauseGameResult
{
    public function __construct(
        public string $gameId,
        public CarbonImmutable $pausedAt,
        public PauseGameOutcome $outcome,
    ) {}
}
