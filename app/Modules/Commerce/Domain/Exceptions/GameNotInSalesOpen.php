<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\Shared\Domain\Exceptions\DomainException;

final class GameNotInSalesOpen extends DomainException
{
    public static function from(GameStatus $current): self
    {
        return new self(
            "Game is not accepting reservations (current status: {$current->value})."
        );
    }
}
