<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class IdempotencyKeyMismatch extends DomainException
{
    public static function forKey(string $key): self
    {
        return new self(
            "Idempotency-Key '{$key}' was previously used with a different request payload."
        );
    }
}
