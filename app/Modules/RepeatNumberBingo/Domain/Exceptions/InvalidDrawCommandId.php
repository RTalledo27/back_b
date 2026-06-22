<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class InvalidDrawCommandId extends DomainException
{
    public static function notAUuid(string $given): self
    {
        return new self(sprintf('Draw command id must be a valid UUID, got "%s".', $given));
    }
}
