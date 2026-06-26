<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use Illuminate\Support\Str;

final readonly class SocialUserData
{
    public function __construct(
        public string $provider,
        public string $providerId,
        public ?string $email,
        public bool $emailVerified,
        public string $name,
    ) {}

    public function normalizedEmail(): ?string
    {
        if ($this->email === null || trim($this->email) === '') {
            return null;
        }

        return Str::of($this->email)->trim()->lower()->toString();
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->emailVerified && $this->normalizedEmail() !== null;
    }
}
