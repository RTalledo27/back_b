<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class NumberNotAvailableForReservation extends DomainException
{
    /**
     * @param  list<string>  $gameNumberIds
     */
    public static function forIds(array $gameNumberIds): self
    {
        $list = implode(', ', $gameNumberIds);

        return new self(
            "One or more numbers are not available for reservation: {$list}."
        );
    }
}
