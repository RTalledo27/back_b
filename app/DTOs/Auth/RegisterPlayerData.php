<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use Illuminate\Support\Str;

final readonly class RegisterPlayerData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
    ) {}

    /**
     * @param  array{name:string,email:string,password:string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: trim($data['name']),
            email: self::normalizeEmail($data['email']),
            password: $data['password'],
        );
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::of($email)->trim()->lower()->toString();
    }
}
