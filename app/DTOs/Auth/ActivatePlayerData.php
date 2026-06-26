<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

final readonly class ActivatePlayerData
{
    public function __construct(
        public string $token,
        public string $password,
    ) {}
}
