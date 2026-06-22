<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class GameNumbersDoNotBelongToGame extends DomainException
{
    /**
     * @param  list<string>  $offendingIds
     */
    public static function offendingIds(string $gameId, array $offendingIds): self
    {
        $list = implode(', ', $offendingIds);

        return new self(
            "Game numbers do not belong to game {$gameId} or do not exist: {$list}."
        );
    }
}
