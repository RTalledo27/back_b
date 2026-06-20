<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\Shared\Domain\Exceptions\DomainException;

final class InvalidGameTransition extends DomainException
{
    public static function from(GameStatus $current, GameStatus $next): self
    {
        return new self(
            "Cannot transition game from {$current->value} to {$next->value}."
        );
    }
}
