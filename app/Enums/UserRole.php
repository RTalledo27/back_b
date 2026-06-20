<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Player = 'player';

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }
}
