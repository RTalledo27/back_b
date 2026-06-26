<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Enums\SocialLinkOutcome;
use App\Models\UserSocialAccount;

final readonly class SocialLinkResult
{
    public function __construct(
        public SocialLinkOutcome $outcome,
        public ?UserSocialAccount $account,
    ) {}
}
