<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\DTOs\PublicGameUpdateReason;
use App\Modules\RepeatNumberBingo\Application\DTOs\ResumeGameData;
use App\Modules\RepeatNumberBingo\Application\DTOs\ResumeGameOutcome;
use App\Modules\RepeatNumberBingo\Application\DTOs\ResumeGameResult;
use App\Modules\RepeatNumberBingo\Application\Services\CommittedPublicGameUpdatesDispatcher;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameResumed;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameEngineAutomationInactive;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameEngineConfiguration;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineGridCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Transition a paused game → running.
 *
 * Lock order:
 *   1. Game FOR UPDATE (engine-wide root lock).
 *
 * Preconditions (under lock):
 *   - auto_draw_enabled = true. Resume must schedule next_draw_at; a
 *     manual game must never carry next_draw_at.
 *   - draw_interval_seconds in [config.min, config.max].
 *
 * Integrity (raised as GameLifecycleIntegrityViolation, NOT silently repaired):
 *   - Fresh transition (paused): started_at IS NOT NULL, completed_at IS NULL,
 *     paused_at IS NOT NULL, next_draw_at IS NULL.
 *   - Replay (running): started_at IS NOT NULL.
 *
 * Side effects on the games row (atomic with the transition):
 *   - paused_at = null
 *   - next_draw_at = first slot strictly after now() aligned with
 *     started_at + N * draw_interval_seconds (delegated to
 *     EngineGridCalculator::skipToNext using started_at as anchor).
 *
 * Outcomes:
 *   - running → AlreadyRunning (no audit, no event).
 *   - paused  → Resumed (audit + event after commit).
 *   - any other status → InvalidGameTransition.
 */
final class ResumeGameAction
{
    public function __construct(
        private readonly EngineGridCalculator $grid,
        private readonly CommittedPublicGameUpdatesDispatcher $publicUpdates,
    ) {}

    public function execute(ResumeGameData $data): ResumeGameResult
    {
        $result = DB::transaction(
            fn (): ResumeGameResult => $this->executeWithinTransaction($data),
        );

        if ($result->outcome === ResumeGameOutcome::Resumed && $data->actor->userId !== null) {
            try {
                GameResumed::dispatch(
                    $result->gameId,
                    $data->actor->userId,
                    $result->resumedAt->toIso8601String(),
                    $result->nextDrawAt->toIso8601String(),
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($result->outcome === ResumeGameOutcome::Resumed) {
            $this->publicUpdates->dispatch(
                $result->gameId,
                PublicGameUpdateReason::Resumed,
                $result->resumedAt,
            );
        }

        return $result;
    }

    public function executeWithinTransaction(ResumeGameData $data): ResumeGameResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'ResumeGameAction::executeWithinTransaction requires an active database transaction.'
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

        // Replay path — already running. Validate integrity BEFORE returning AlreadyRunning.
        if ($game->status === GameStatus::Running) {
            if ($game->started_at === null) {
                throw GameLifecycleIntegrityViolation::withContext(
                    'Game is running but started_at is null.',
                    ['game_id' => $game->id, 'status' => $game->status->value],
                );
            }

            return new ResumeGameResult(
                gameId: $game->id,
                resumedAt: $game->started_at->toImmutable(),
                nextDrawAt: ($game->next_draw_at ?? $game->started_at)->toImmutable(),
                outcome: ResumeGameOutcome::AlreadyRunning,
            );
        }

        if ($game->status !== GameStatus::Paused) {
            throw InvalidGameTransition::from($game->status, GameStatus::Running);
        }

        // Fresh transition — paused. Integrity preconditions first.
        $this->assertPausedIntegrity($game);
        $this->assertValidEngineConfiguration($game);

        $now = CarbonImmutable::now();
        $nextDrawAt = $this->grid->skipToNext(
            $game->started_at->toImmutable(),
            $game->draw_interval_seconds,
            $now,
        );

        $game->transitionTo(GameStatus::Running);
        $game->paused_at = null;
        $game->next_draw_at = $nextDrawAt;
        $game->save();

        GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::GameResumed,
            'payload' => [
                'actor_type' => $data->actor->type->value,
                'actor_user_id' => $data->actor->userId,
                'resumed_at' => $now->toIso8601String(),
                'next_draw_at' => $nextDrawAt->toIso8601String(),
            ],
            'actor_user_id' => $data->actor->userId,
            'occurred_at' => $now,
        ]);

        return new ResumeGameResult(
            gameId: $game->id,
            resumedAt: $now,
            nextDrawAt: $nextDrawAt,
            outcome: ResumeGameOutcome::Resumed,
        );
    }

    private function assertPausedIntegrity(Game $game): void
    {
        if ($game->started_at === null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is paused but started_at is null.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }

        if ($game->completed_at !== null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is paused but completed_at is already set.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }

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
    }

    private function assertValidEngineConfiguration(Game $game): void
    {
        $min = (int) config('engine.draw_interval_min_seconds', 10);
        $max = (int) config('engine.draw_interval_max_seconds', 3600);

        if ($game->draw_interval_seconds < $min || $game->draw_interval_seconds > $max) {
            throw InvalidGameEngineConfiguration::invalidInterval(
                $game->id,
                $game->draw_interval_seconds,
                $min,
                $max,
            );
        }
    }
}
