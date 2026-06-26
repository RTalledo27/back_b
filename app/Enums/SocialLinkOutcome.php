<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialLinkOutcome: string
{
    case SocialLinked = 'social_linked';
    case AlreadyLinked = 'already_linked';
    case SocialIdentityConflict = 'social_identity_conflict';
    case ProviderAlreadyLinked = 'provider_already_linked';
}
