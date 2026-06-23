<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Services;

use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberResult;
use App\Modules\RepeatNumberBingo\Application\DTOs\PublicGameUpdateReason;
use App\Modules\RepeatNumberBingo\Domain\Events\GameCompleted;
use App\Modules\RepeatNumberBingo\Domain\Events\GameNumberDrawn;
use App\Modules\RepeatNumberBingo\Domain\Events\GameWinnerDeclared;
use Throwable;

final class CommittedDrawEventsDispatcher
{
    public function __construct(
        private readonly CommittedPublicGameUpdatesDispatcher $publicUpdates,
    ) {}

    public function dispatch(DrawGameNumberResult $result, string $commandId): void
    {
        if ($result->wasReplay) {
            return;
        }

        $this->dispatchSafely(static fn () => GameNumberDrawn::dispatch(
            $result->gameId,
            $result->drawId,
            $commandId,
            $result->sequence,
            $result->drawnNumber,
            $result->currentHits,
            $result->hitsRequired,
            $result->numberIsSold,
            $result->drawnAt->toIso8601String(),
        ));

        $this->publicUpdates->dispatch(
            $result->gameId,
            PublicGameUpdateReason::NumberDrawn,
            $result->drawnAt,
        );

        if (! $result->winnerCreated) {
            return;
        }

        $this->dispatchSafely(static fn () => GameWinnerDeclared::dispatch(
            $result->gameId,
            $result->winnerEntryId ?? '',
            $result->drawId,
            $result->drawnAt->toIso8601String(),
        ));

        $this->dispatchSafely(static fn () => GameCompleted::dispatch(
            $result->gameId,
            $result->drawId,
            $result->drawnAt->toIso8601String(),
        ));
    }

    private function dispatchSafely(callable $dispatch): void
    {
        try {
            $dispatch();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
