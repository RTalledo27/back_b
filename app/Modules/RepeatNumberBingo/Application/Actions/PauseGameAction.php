<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\DTOs\PauseGameData;
use App\Modules\RepeatNumberBingo\Application\DTOs\PauseGameOutcome;
use App\Modules\RepeatNumberBingo\Application\DTOs\PauseGameResult;
use App\Modules\RepeatNumberBingo\Application\DTOs\PublicGameUpdateReason;
use App\Modules\RepeatNumberBingo\Application\Services\CommittedPublicGameUpdatesDispatcher;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GamePaused;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameEngineAutomationInactive;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Transition a running game → paused.
 *
 * Lock order:
 *   1. Game FOR UPDATE (engine-wide root lock).
 *
 * Preconditions (under lock):
 *   - auto_draw_enabled = true. Pause is an engine-automation operation;
 *     a manual game cannot be paused because Resume would have to set
 *     next_draw_at — which a manual game must never carry.
 *
 * Integrity (raised as GameLifecycleIntegrityViolation, NOT silently repaired):
 *   - Fresh transition (running): started_at IS NOT NULL, completed_at IS NULL,
 *     paused_at IS NULL.
 *   - Replay (paused): paused_at IS NOT NULL, next_draw_at IS NULL,
 *     completed_at IS NULL.
 *
 * Outcomes:
 *   - paused → AlreadyPaused (no audit, no event).
 *   - running → Paused (audit + event after commit).
 *   - any other status → InvalidGameTransition.
 *
 * Side effects on the games row (atomic with the transition):
 *   - paused_at = now
 *   - next_draw_at = null
 *   - last_consumed_tick_at preserved
 */
final class PauseGameAction
{
    public function __construct(
        private readonly CommittedPublicGameUpdatesDispatcher $publicUpdates,
    ) {}

    public function execute(PauseGameData $data): PauseGameResult
    {
        $result = DB::transaction(
            fn (): PauseGameResult => $this->executeWithinTransaction($data),
        );

        if ($result->outcome === PauseGameOutcome::Paused && $data->actor->userId !== null) {
            try {
                GamePaused::dispatch(
                    $result->gameId,
                    $data->actor->userId,
                    $result->pausedAt->toIso8601String(),
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($result->outcome === PauseGameOutcome::Paused) {
            $this->publicUpdates->dispatch(
                $result->gameId,
                PublicGameUpdateReason::Paused,
                $result->pausedAt,
            );
        }

        return $result;
    }

    public function executeWithinTransaction(PauseGameData $data): PauseGameResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'PauseGameAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        /** @var ?Game $game */
        $game = Game::query()
            ->whereKey($data->gameId)
            ->lockForUpdate()
            ->first();

        if ($game === null) {
            throw (new ModelNotFoundException)->setModel(Game::class, [$data->gameId]);
        }

        if (! $game->auto_draw_enabled) {
            throw GameEngineAutomationInactive::forGame($game->id);
        }

        // Replay path — already paused. Validate integrity BEFORE returning AlreadyPaused.
        if ($game->status === GameStatus::Paused) {
            $this->assertPausedIntegrity($game);

            return new PauseGameResult(
                gameId: $game->id,
                pausedAt: $game->paused_at->toImmutable(),
                outcome: PauseGameOutcome::AlreadyPaused,
            );
        }

        if ($game->status !== GameStatus::Running) {
            throw InvalidGameTransition::from($game->status, GameStatus::Paused);
        }

        // Fresh transition — running. Integrity preconditions first.
        $this->assertRunningIntegrity($game);

        $pausedAt = CarbonImmutable::now();

        $game->transitionTo(GameStatus::Paused);
        $game->paused_at = $pausedAt;
        $game->next_draw_at = null;
        // last_consumed_tick_at preserved intentionally.
        $game->save();

        GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::GamePaused,
            'payload' => [
                'actor_type' => $data->actor->type->value,
                'actor_user_id' => $data->actor->userId,
                'paused_at' => $pausedAt->toIso8601String(),
            ],
            'actor_user_id' => $data->actor->userId,
            'occurred_at' => $pausedAt,
        ]);

        return new PauseGameResult(
            gameId: $game->id,
            pausedAt: $pausedAt,
            outcome: PauseGameOutcome::Paused,
        );
    }

    private function assertRunningIntegrity(Game $game): void
    {
        if ($game->started_at === null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is running but started_at is null.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }

        if ($game->completed_at !== null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is running but completed_at is already set.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }

        if ($game->paused_at !== null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is running but paused_at is set.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }
    }

    private function assertPausedIntegrity(Game $game): void
    {
        if ($game->paused_at === null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is paused but paused_at is null.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }

        if ($game->next_draw_at !== null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is paused but next_draw_at is set.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }

        if ($game->completed_at !== null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is paused but completed_at is set.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }
    }
}
