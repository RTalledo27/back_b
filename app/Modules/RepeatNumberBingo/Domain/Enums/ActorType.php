<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Enums;

enum ActorType: string
{
    case Admin = 'admin';
    case System = 'system';
}
