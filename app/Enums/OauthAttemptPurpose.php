<?php

declare(strict_types=1);

namespace App\Enums;

enum OauthAttemptPurpose: string
{
    case Login = 'login';
    case Link = 'link';
}
