<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\DTOs\AutoPauseGameOutcome;
use App\Modules\RepeatNumberBingo\Application\DTOs\PublicGameUpdateReason;
use App\Modules\RepeatNumberBingo\Application\Services\CommittedPublicGameUpdatesDispatcher;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AutoPauseGameAfterIntegrityFailureAction
{
    public function __construct(
        private readonly CommittedPublicGameUpdatesDispatcher $publicUpdates,
    ) {}

    public function execute(
        EngineTick $tick,
        Throwable $exception,
        string $failureCode,
    ): AutoPauseGameOutcome {
        $pausedAt = null;

        $outcome = DB::transaction(function () use ($tick, $exception, $failureCode, &$pausedAt): AutoPauseGameOutcome {
            /** @var ?Game $game */
            $game = Game::query()
                ->whereKey($tick->gameId)
                ->lockForUpdate()
                ->first();

            if ($game === null || ! $game->auto_draw_enabled) {
                return AutoPauseGameOutcome::NotApplicable;
            }

            if ($game->status === GameStatus::Paused) {
                return AutoPauseGameOutcome::AlreadyPaused;
            }

            if (
                $game->status !== GameStatus::Running
                || $game->next_draw_at === null
                || ! $game->next_draw_at->toImmutable()->equalTo($tick->scheduledAt)
            ) {
                return AutoPauseGameOutcome::NotApplicable;
            }

            $pausedAt = CarbonImmutable::now();

            $game->transitionTo(GameStatus::Paused);
            $game->paused_at = $pausedAt;
            $game->next_draw_at = null;
            $game->save();

            GameEvent::create([
                'game_id' => $game->id,
                'type' => GameEventType::GameAutoPaused,
                'payload' => [
                    'failure_type' => 'integrity',
                    'failure_code' => $failureCode,
                    'exception_class' => $exception::class,
                    'command_id' => $tick->commandId->toString(),
                    'scheduled_at' => $tick->scheduledAt->toIso8601String(),
                    'paused_at' => $pausedAt->toIso8601String(),
                ],
                'actor_user_id' => null,
                'occurred_at' => $pausedAt,
            ]);

            return AutoPauseGameOutcome::Paused;
        });

        if ($outcome === AutoPauseGameOutcome::Paused && $pausedAt instanceof CarbonImmutable) {
            $this->publicUpdates->dispatch(
                $tick->gameId,
                PublicGameUpdateReason::Paused,
                $pausedAt,
            );
        }

        return $outcome;
    }
}
