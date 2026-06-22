<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\Shared\Domain\Exceptions\DomainException;

final class InvalidGameNumberTransition extends DomainException
{
    public static function from(GameNumberStatus $current, GameNumberStatus $next): self
    {
        return new self(
            "Cannot transition game number from {$current->value} to {$next->value}."
        );
    }
}
