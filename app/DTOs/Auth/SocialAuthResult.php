<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Enums\SocialAuthOutcome;
use App\Models\User;

final readonly class SocialAuthResult
{
    public function __construct(
        public SocialAuthOutcome $outcome,
        public ?User $user,
    ) {}
}
