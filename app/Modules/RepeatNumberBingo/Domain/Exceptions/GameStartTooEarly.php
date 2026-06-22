<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;
use Carbon\CarbonInterface;

final class GameStartTooEarly extends DomainException
{
    public static function for(string $gameId, CarbonInterface $scheduledAt, CarbonInterface $now): self
    {
        return new self(sprintf(
            'Game %s cannot start before scheduled_start_at (%s); current time is %s.',
            $gameId,
            $scheduledAt->toIso8601String(),
            $now->toIso8601String(),
        ));
    }
}
