<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class GameHasNoScheduledStart extends DomainException
{
    public static function for(string $gameId): self
    {
        return new self(sprintf('Game %s has no scheduled_start_at; cannot be started.', $gameId));
    }
}
