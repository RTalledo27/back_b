<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class ImmutableModelException extends DomainException
{
    public static function forModel(string $modelClass, string $operation): self
    {
        return new self("{$modelClass} is append-only and cannot be {$operation}.");
    }

    /**
     * @param  list<string>  $attributes
     */
    public static function forAttributes(string $modelClass, array $attributes): self
    {
        $list = implode(', ', $attributes);

        return new self(
            "{$modelClass} has immutable attributes that cannot be modified after creation: {$list}."
        );
    }
}
