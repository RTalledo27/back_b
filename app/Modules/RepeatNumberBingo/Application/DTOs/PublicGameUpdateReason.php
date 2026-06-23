<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

enum PublicGameUpdateReason: string
{
    case Started = 'started';
    case NumberDrawn = 'number_drawn';
    case Paused = 'paused';
    case Resumed = 'resumed';
}
