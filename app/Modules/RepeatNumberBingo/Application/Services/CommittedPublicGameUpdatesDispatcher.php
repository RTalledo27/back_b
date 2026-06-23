<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Services;

use App\Modules\RepeatNumberBingo\Application\Contracts\PublicGameUpdatesPublisher;
use App\Modules\RepeatNumberBingo\Application\DTOs\PublicGameUpdateReason;
use Carbon\CarbonImmutable;
use Throwable;

final class CommittedPublicGameUpdatesDispatcher
{
    public function __construct(
        private readonly PublicGameUpdatesPublisher $publisher,
    ) {}

    public function dispatch(
        string $gameId,
        PublicGameUpdateReason $reason,
        CarbonImmutable $occurredAt,
    ): void {
        try {
            $this->publisher->publish($gameId, $reason, $occurredAt);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
