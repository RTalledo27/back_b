<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\Shared\Domain\Exceptions\DomainException;

final class InvalidGamePrizeFundingTransition extends DomainException
{
    public static function from(GamePrizeFundingStatus $current, GamePrizeFundingStatus $next): self
    {
        return new self(
            "Cannot transition prize funding from {$current->value} to {$next->value}."
        );
    }
}
