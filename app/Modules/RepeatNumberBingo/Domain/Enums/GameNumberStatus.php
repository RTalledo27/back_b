<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Enums;

enum GameNumberStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
}
