<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class WinnerPayoutNotFound extends DomainException
{
    public static function forGame(string $gameId): self
    {
        return new self("No payout found for game {$gameId}.");
    }
}
