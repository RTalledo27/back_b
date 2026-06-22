<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class UnsupportedDrawResultVersion extends DomainException
{
    public static function got(mixed $given): self
    {
        $rendered = is_scalar($given) ? (string) $given : gettype($given);

        return new self(sprintf('Unsupported DrawGameNumberResult schema_version: %s.', $rendered));
    }

    public static function missing(): self
    {
        return new self('DrawGameNumberResult payload is missing schema_version.');
    }
}
