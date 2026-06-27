<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class WinnerEntryNotRefundable extends DomainException
{
    public static function entryIsWinner(string $entryId): self
    {
        return new self(
            "GameEntry {$entryId} belongs to the game winner and cannot be refunded."
        );
    }

    public static function gameWinnerReferencesOrderEntry(string $orderId): self
    {
        return new self(
            "The game winner was declared on a number from order {$orderId}. This order cannot be refunded."
        );
    }
}
