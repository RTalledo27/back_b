<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Models\User;

final readonly class AuthTokenResult
{
    /**
     * @param  list<string>  $abilities
     */
    public function __construct(
        public User $user,
        public string $plainTextToken,
        public array $abilities,
        public string $tokenType = 'Bearer',
    ) {}
}
