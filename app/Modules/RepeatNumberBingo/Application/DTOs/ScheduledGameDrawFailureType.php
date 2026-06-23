<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

enum ScheduledGameDrawFailureType: string
{
    case Expected = 'expected';
    case Transient = 'transient';
    case Integrity = 'integrity';
}
