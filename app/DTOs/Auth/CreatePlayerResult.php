<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Enums\CreatePlayerOutcome;
use App\Models\User;
use App\Models\UserInvitation;

final readonly class CreatePlayerResult
{
    public function __construct(
        public CreatePlayerOutcome $outcome,
        public User $user,
        public ?UserInvitation $invitation,
        public ?string $plainToken,
    ) {}
}
