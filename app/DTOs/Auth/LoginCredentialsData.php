<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

final readonly class LoginCredentialsData
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}

    /**
     * @param  array{email:string,password:string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            email: RegisterPlayerData::normalizeEmail($data['email']),
            password: $data['password'],
        );
    }
}
