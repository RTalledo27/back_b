<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

final class EmailVerificationException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('Email verification failed: '.$reason);
    }
}
