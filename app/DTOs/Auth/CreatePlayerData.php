<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use Illuminate\Support\Str;

final readonly class CreatePlayerData
{
    public function __construct(
        public string $name,
        public string $email,
        public int $invitedByUserId,
    ) {}

    public static function normalizeEmail(string $email): string
    {
        return Str::of($email)->trim()->lower()->toString();
    }
}
