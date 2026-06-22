<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class IdempotencyInProgress extends DomainException
{
    public static function forKey(string $key): self
    {
        return new self(
            "Idempotency-Key '{$key}' is currently in progress. Retry shortly."
        );
    }
}
