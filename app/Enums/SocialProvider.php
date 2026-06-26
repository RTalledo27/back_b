<?php

declare(strict_types=1);

namespace App\Enums;

enum SocialProvider: string
{
    case Google = 'google';
    case Facebook = 'facebook';

    /** @return list<string> */
    public static function allowed(): array
    {
        return array_column(self::cases(), 'value');
    }
}
