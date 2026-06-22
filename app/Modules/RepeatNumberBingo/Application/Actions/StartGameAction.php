<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\Contracts\GameStartReadinessChecker;
use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameData;
use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameOutcome;
use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameResult;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameStarted;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameAlreadyCompleted;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameHasNoScheduledStart;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameStartTooEarly;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Transition a game sales_closed → running.
 *
 * Lock order (Phase 3.4):
 *   1. Game FOR UPDATE  (engine-wide root lock — shared with
 *      ApprovePaymentAction)
 *
 *   After the lock is held, GameStartReadinessChecker verifies that
 *   nothing on the sales side is still pending. The checker reads only;
 *   it does not lock additional rows.
 *
 * Idempotency:
 *   - running + started_at NOT NULL → AlreadyStarted, reconstruct
 *     result from operational state, no audit, no event.
 *   - sales_closed + started_at NOT NULL → integrity violation.
 *   - running + started_at IS NULL → integrity violation.
 *   - completed_at IS NOT NULL → integrity violation (any prior start
 *     attempt should already be Completed, never re-runnable).
 *   - completed → GameAlreadyCompleted.
 *   - Other non-SalesClosed states → InvalidGameTransition.
 *
 * Audit + event:
 *   - One GameEvent::GameStarted inside the transaction.
 *   - GameStarted domain event AFTER commit, only when the transition
 *     was newly applied. Listener exceptions are reported, never roll
 *     back the start.
 */
final class StartGameAction
{
    public function __construct(
        private readonly GameStartReadinessChecker $readiness,
    ) {}

    public function execute(StartGameData $data): StartGameResult
    {
        $result = DB::transaction(
            fn (): StartGameResult => $this->executeWithinTransaction($data),
        );

        if ($result->outcome === StartGameOutcome::Started) {
            try {
                GameStarted::dispatch(
                    $result->gameId,
                    $data->actorUserId,
                    $result->scheduledStartAt->toIso8601String(),
                    $result->startedAt->toIso8601String(),
                    $result->confirmedEntriesCount,
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    public function executeWithinTransaction(StartGameData $data): StartGameResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'StartGameAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        // 1. Engine-wide root lock.
        /** @var ?Game $game */
        $game = Game::query()
            ->whereKey($data->gameId)
            ->lockForUpdate()
            ->first();

        if ($game === null) {
            throw (new ModelNotFoundException)->setModel(Game::class, [$data->gameId]);
        }

        // 2. Classify the game-completed case before doing any other
        //    short-circuit. A consistent Completed row is NOT corruption.
        if ($game->status === GameStatus::Completed) {
            if ($game->started_at !== null && $game->completed_at !== null) {
                throw GameAlreadyCompleted::for($game->id);
            }
            // Completed but missing either started_at or completed_at is
            // an impossible combination — that IS corruption.
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is completed but lifecycle timestamps are missing.',
                [
                    'game_id' => $game->id,
                    'status' => $game->status->value,
                    'started_at' => $game->started_at?->toIso8601String(),
                    'completed_at' => $game->completed_at?->toIso8601String(),
                ],
            );
        }

        // 3. Any other status carrying completed_at is corruption.
        if ($game->completed_at !== null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game has completed_at set but is not in completed status.',
                ['game_id' => $game->id, 'status' => $game->status->value, 'completed_at' => $game->completed_at->toIso8601String()],
            );
        }

        if ($game->status === GameStatus::Running && $game->started_at === null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is running but started_at is null.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }

        if ($game->status === GameStatus::SalesClosed && $game->started_at !== null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is sales_closed but started_at is already set.',
                ['game_id' => $game->id, 'status' => $game->status->value, 'started_at' => $game->started_at->toIso8601String()],
            );
        }

        // 4. Idempotent replay — already correctly running.
        if ($game->status === GameStatus::Running && $game->started_at !== null) {
            return $this->buildResultFromOperationalState($game, StartGameOutcome::AlreadyStarted);
        }

        if ($game->status !== GameStatus::SalesClosed) {
            throw InvalidGameTransition::from($game->status, GameStatus::Running);
        }

        if ($game->scheduled_start_at === null) {
            throw GameHasNoScheduledStart::for($game->id);
        }

        $now = CarbonImmutable::now();

        if ($now->lessThan($game->scheduled_start_at)) {
            throw GameStartTooEarly::for(
                $game->id,
                $game->scheduled_start_at->toImmutable(),
                $now,
            );
        }

        // 5. Sales-side readiness — run after Game is locked.
        $readiness = $this->readiness->assertReadyForStart($game->id);

        // 6. Apply transition. Single canonical timestamp used everywhere.
        $startedAt = $now;

        $game->transitionTo(GameStatus::Running);
        $game->started_at = $startedAt;
        $game->save();

        // 7. Audit (critical, inside transaction). One row exactly.
        GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::GameStarted,
            'payload' => [
                'actor_user_id' => $data->actorUserId,
                'scheduled_start_at' => $game->scheduled_start_at->toIso8601String(),
                'started_at' => $startedAt->toIso8601String(),
                'confirmed_entries_count' => $readiness->confirmedEntriesCount,
            ],
            'actor_user_id' => $data->actorUserId,
            'occurred_at' => $startedAt,
        ]);

        return new StartGameResult(
            gameId: $game->id,
            startedAt: $startedAt,
            scheduledStartAt: $game->scheduled_start_at->toImmutable(),
            confirmedEntriesCount: $readiness->confirmedEntriesCount,
            outcome: StartGameOutcome::Started,
        );
    }

    /**
     * Reconstruct the Result for an already-started game from operational
     * tables only — game_events is NOT consulted.
     */
    private function buildResultFromOperationalState(Game $game, StartGameOutcome $outcome): StartGameResult
    {
        $confirmedEntries = (int) GameEntry::query()
            ->where('game_id', $game->id)
            ->where('status', EntryStatus::Confirmed->value)
            ->count();

        return new StartGameResult(
            gameId: $game->id,
            startedAt: $game->started_at->toImmutable(),
            scheduledStartAt: $game->scheduled_start_at?->toImmutable() ?? $game->started_at->toImmutable(),
            confirmedEntriesCount: $confirmedEntries,
            outcome: $outcome,
        );
    }
}
