<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Infrastructure\Broadcasting;

use App\Modules\RepeatNumberBingo\Application\Contracts\PublicGameUpdatesPublisher;
use App\Modules\RepeatNumberBingo\Application\DTOs\PublicGameUpdateReason;
use App\Modules\RepeatNumberBingo\Application\Queries\GetPublicGameBroadcastSnapshotQuery;
use App\Modules\RepeatNumberBingo\Infrastructure\Broadcasting\Events\PublicGameUpdated;
use Carbon\CarbonImmutable;

final class LaravelPublicGameUpdatesPublisher implements PublicGameUpdatesPublisher
{
    public function __construct(
        private readonly GetPublicGameBroadcastSnapshotQuery $snapshotQuery,
    ) {}

    public function publish(
        string $gameId,
        PublicGameUpdateReason $reason,
        CarbonImmutable $occurredAt,
    ): void {
        $payload = $this->snapshotQuery->forGame($gameId, $reason, $occurredAt);

        PublicGameUpdated::dispatch($payload['game_slug'], $payload);
    }
}
