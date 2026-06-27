<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

final class PasswordResetException extends RuntimeException
{
    public function __construct(string $brokerStatus)
    {
        parent::__construct('Password reset failed: '.$brokerStatus);
    }
}
