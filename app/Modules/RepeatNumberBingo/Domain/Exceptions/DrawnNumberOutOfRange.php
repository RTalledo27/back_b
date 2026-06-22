<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class DrawnNumberOutOfRange extends DomainException
{
    public static function for(int $number, int $minimum, int $maximum): self
    {
        return new self(
            sprintf('Drawn number %d is outside the [%d, %d] range.', $number, $minimum, $maximum)
        );
    }
}
