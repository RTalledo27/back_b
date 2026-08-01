<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Enums;

enum GamePrizeFundingEventType: string
{
    case FundingCreated = 'funding_created';
    case FundingRecorded = 'funding_recorded';
    case FundingReserved = 'funding_reserved';
    case FundingReleased = 'funding_released';
}
