<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Contracts;

use App\Modules\RepeatNumberBingo\Application\DTOs\PublicGameUpdateReason;
use Carbon\CarbonImmutable;

interface PublicGameUpdatesPublisher
{
    public function publish(
        string $gameId,
        PublicGameUpdateReason $reason,
        CarbonImmutable $occurredAt,
    ): void;
}
