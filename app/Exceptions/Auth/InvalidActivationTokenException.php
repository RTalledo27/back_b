<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

final class InvalidActivationTokenException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('The activation token is invalid or cannot be used.');
    }

    public static function notFound(): self
    {
        return new self('not_found');
    }

    public static function consumed(): self
    {
        return new self('consumed');
    }

    public static function revoked(): self
    {
        return new self('revoked');
    }

    public static function expired(): self
    {
        return new self('expired');
    }

    public static function alreadyActive(): self
    {
        return new self('already_active');
    }
}
