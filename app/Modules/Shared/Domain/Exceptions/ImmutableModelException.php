<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class ImmutableModelException extends DomainException
{
    public static function forModel(string $modelClass, string $operation): self
    {
        return new self("{$modelClass} is append-only and cannot be {$operation}.");
    }
}
