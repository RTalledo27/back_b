<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class GameAlreadyCompleted extends DomainException
{
    public static function for(string $gameId): self
    {
        return new self(sprintf('Game %s is already completed; no further draws allowed.', $gameId));
    }
}
