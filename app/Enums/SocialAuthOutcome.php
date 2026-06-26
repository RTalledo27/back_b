<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialAuthOutcome: string
{
    case Authenticated = 'authenticated';
    case Created = 'created';
    case AccountLinkRequired = 'account_link_required';
    case VerifiedEmailRequired = 'verified_email_required';

    public function isSuccess(): bool
    {
        return $this === self::Authenticated || $this === self::Created;
    }
}
