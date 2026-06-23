<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberResult;
use App\Modules\RepeatNumberBingo\Application\Services\CommittedDrawEventsDispatcher;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\DrawnNumberOutOfRange;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameAlreadyCompleted;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameEngineAutomationActive;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameParticipationIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Phase 3.6: single-draw execution INCLUDING winner resolution.
 *
 * Lock order:
 *   1. Game           FOR UPDATE  — root engine lock
 *   2. DrawCommand    by (game_id, command_id) — replay detection
 *   3. GameNumber     FOR UPDATE
 *   4. GameEntry      FOR UPDATE — single query, no double read
 *
 * Persistence order inside the transaction:
 *   game_draws   (canonical source of truth — inserted FIRST)
 *   game_number_counters  (projection — UPSERT after the source row)
 *   game_winners + Game state mutation (on winner branch)
 *   game_events  (audits inside the same tx)
 *   draw_commands (idempotency snapshot inserted LAST)
 *
 * Post-commit dispatch (each protected individually):
 *   GameNumberDrawn  →  GameWinnerDeclared (when winner)  →  GameCompleted
 *
 * Replays (same command_id) hydrate from result_payload and emit no
 * events. Listener exceptions are reported and cannot revert any of the
 * committed rows.
 */
final class DrawGameNumberAction
{
    public function __construct(
        private readonly DrawNumberStrategy $drawStrategy,
        private readonly CommittedDrawEventsDispatcher $events,
    ) {}

    public function execute(DrawGameNumberData $data): DrawGameNumberResult
    {
        $result = DB::transaction(
            fn (): DrawGameNumberResult => $this->executeWithinTransaction($data),
        );

        if (! $result->wasReplay) {
            $this->events->dispatch($result, $data->commandId->toString());
        }

        return $result;
    }

    public function executeWithinTransaction(
        DrawGameNumberData $data,
        bool $automated = false,
        ?Game $lockedGame = null,
    ): DrawGameNumberResult {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'DrawGameNumberAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        // 1. Engine root lock.
        /** @var ?Game $game */
        $game = $lockedGame ?? Game::query()
            ->whereKey($data->gameId)
            ->lockForUpdate()
            ->first();

        if ($game === null) {
            throw (new ModelNotFoundException)->setModel(Game::class, [$data->gameId]);
        }

        if ($game->id !== $data->gameId) {
            throw new LogicException('The locked Game does not match DrawGameNumberData::gameId.');
        }

        // 2. Replay detection — read the persisted command snapshot.
        $command = DrawCommand::query()
            ->where('game_id', $game->id)
            ->where('command_id', $data->commandId->toString())
            ->first();

        if ($command !== null) {
            return DrawGameNumberResult::fromArray($command->result_payload, asReplay: true);
        }

        // 3. Game-state classification (consistent ordering — same logic
        //    as StartGameAction so corruption never silently turns into a
        //    domain transition).
        if ($game->status === GameStatus::Completed) {
            if ($game->started_at !== null && $game->completed_at !== null) {
                throw GameAlreadyCompleted::for($game->id);
            }
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

        if ($game->completed_at !== null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game has completed_at set but is not in completed status.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }

        if ($game->status === GameStatus::Running && $game->started_at === null) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is running but started_at is null.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }

        if ($game->status !== GameStatus::Running) {
            throw InvalidGameTransition::from($game->status, GameStatus::Running);
        }

        // Manual draws are prohibited while the engine scheduler is active.
        // This guard runs under the Game FOR UPDATE lock.
        if ($game->auto_draw_enabled && ! $automated) {
            throw GameEngineAutomationActive::forGame($game->id);
        }

        // 4. There must not already be a winner for a running game.
        $winnerExists = GameWinner::query()->where('game_id', $game->id)->exists();
        if ($winnerExists) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Game is running but a winner already exists.',
                ['game_id' => $game->id],
            );
        }

        // 5. Sequence under the Game lock — game_draws is the source of truth.
        $sequence = (int) (DB::table('game_draws')->where('game_id', $game->id)->max('sequence') ?? 0) + 1;

        // 6. Generate the number.
        $drawnNumber = $this->drawStrategy->generate($game->number_min, $game->number_max);

        if ($drawnNumber < $game->number_min || $drawnNumber > $game->number_max) {
            throw DrawnNumberOutOfRange::for($drawnNumber, $game->number_min, $game->number_max);
        }

        // 7. Lock the GameNumber.
        /** @var ?GameNumber $gameNumber */
        $gameNumber = GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', $drawnNumber)
            ->lockForUpdate()
            ->first();

        if ($gameNumber === null) {
            throw GameParticipationIntegrityViolation::withContext(
                'GameNumber row missing for a number inside the configured range.',
                ['game_id' => $game->id, 'number' => $drawnNumber],
            );
        }

        // 8. Single GameEntry query under FOR UPDATE.
        $entry = GameEntry::query()
            ->where('game_number_id', $gameNumber->id)
            ->lockForUpdate()
            ->first();

        // 9. Validate GameNumber ↔ GameEntry consistency.
        $numberIsSold = $this->validateParticipation($game, $gameNumber, $entry);

        // 10. Capture the canonical timestamp for THIS draw. Winner
        //     resolution (when applicable) captures its own $completedAt
        //     later — that timestamp represents the moment of evaluation,
        //     not the moment of extraction. Keeping them separate makes
        //     the temporal invariant drawn_at <= won_at honest by design.
        $drawnAt = CarbonImmutable::now();

        // 11. INSERT game_draws FIRST — this is the canonical source of
        //     truth. game_number_counters is derived from it.
        $drawId = (string) Str::uuid7();
        DB::table('game_draws')->insert([
            'id' => $drawId,
            'game_id' => $game->id,
            'game_number_id' => $gameNumber->id,
            'sequence' => $sequence,
            'drawn_number' => $drawnNumber,
            'drawn_at' => $drawnAt,
            'strategy' => $this->drawStrategy->name(),
            'created_at' => $drawnAt,
        ]);

        // 12. UPSERT counter projection.
        $counterId = (string) Str::uuid7();
        $row = DB::selectOne(
            'INSERT INTO game_number_counters '
            .'(id, game_id, game_number_id, hits_count, last_draw_sequence, created_at, updated_at) '
            .'VALUES (?, ?, ?, 1, ?, NOW(), NOW()) '
            .'ON CONFLICT (game_id, game_number_id) DO UPDATE SET '
            .'    hits_count = game_number_counters.hits_count + 1, '
            .'    last_draw_sequence = EXCLUDED.last_draw_sequence, '
            .'    updated_at = NOW() '
            .'RETURNING hits_count, last_draw_sequence',
            [$counterId, $game->id, $gameNumber->id, $sequence],
        );

        $currentHits = (int) $row->hits_count;
        $lastDrawSequence = (int) $row->last_draw_sequence;

        if ($lastDrawSequence !== $sequence) {
            throw GameLifecycleIntegrityViolation::withContext(
                'Counter last_draw_sequence does not match the freshly assigned sequence.',
                ['game_id' => $game->id, 'expected' => $sequence, 'actual' => $lastDrawSequence],
            );
        }

        // 13. Resolve winner branch.
        $winnerCreated = false;
        $winnerEntryId = null;
        $gameStatusForResult = $game->status->value;

        if ($numberIsSold && $entry !== null) {
            if ($currentHits > $game->hits_required) {
                throw GameParticipationIntegrityViolation::withContext(
                    'Sold number with confirmed entry exceeded hits_required while game was still running.',
                    [
                        'game_id' => $game->id,
                        'game_number_id' => $gameNumber->id,
                        'current_hits' => $currentHits,
                        'hits_required' => $game->hits_required,
                    ],
                );
            }

            if ($currentHits === $game->hits_required) {
                // Resolution captures its own timestamp; drawn_at is the
                // moment of extraction, won_at / completed_at the moment
                // of evaluation. They will frequently coincide, but the
                // domain rule is drawn_at <= won_at, not equality.
                $completedAt = CarbonImmutable::now();
                $this->resolveWinner($game, $gameNumber, $entry, $drawId, $currentHits, $sequence, $completedAt, $data->actorUserId);
                $winnerCreated = true;
                $winnerEntryId = $entry->id;
                $gameStatusForResult = GameStatus::Completed->value;
            }
        }

        // 14. Audit the "unowned number reaches threshold" event only on
        //     the exact equality — later draws of the same unowned number
        //     do not duplicate the audit.
        if (! $numberIsSold && $currentHits === $game->hits_required) {
            GameEvent::create([
                'game_id' => $game->id,
                'type' => GameEventType::UnownedNumberReachedThreshold,
                'payload' => [
                    'game_number_id' => $gameNumber->id,
                    'number' => (int) $gameNumber->number,
                    'sequence' => $sequence,
                    'hits_count' => $currentHits,
                    'hits_required' => $game->hits_required,
                ],
                'actor_user_id' => $data->actorUserId,
                'occurred_at' => $drawnAt,
            ]);
        }

        // 15. Build the result and insert the command in its final form.
        $result = new DrawGameNumberResult(
            gameId: $game->id,
            drawId: $drawId,
            sequence: $sequence,
            drawnNumber: $drawnNumber,
            gameNumberId: $gameNumber->id,
            currentHits: $currentHits,
            hitsRequired: $game->hits_required,
            numberIsSold: $numberIsSold,
            winnerCreated: $winnerCreated,
            winnerEntryId: $winnerEntryId,
            gameStatus: $gameStatusForResult,
            drawnAt: $drawnAt,
            wasReplay: false,
        );

        DrawCommand::create([
            'game_id' => $game->id,
            'command_id' => $data->commandId->toString(),
            'game_draw_id' => $drawId,
            'result_payload' => $result->toPersistablePayload(),
            'completed_at' => $drawnAt,
        ]);

        return $result;
    }

    /**
     * Atomically: transition the entry to Winner, INSERT game_winners,
     * transition the game Running → Resolving → Completed (single tx,
     * single canonical $completedAt), and audit the three resolution
     * events. Any failure here roll back the whole transaction along
     * with the freshly inserted draw / counter row.
     */
    private function resolveWinner(
        Game $game,
        GameNumber $gameNumber,
        GameEntry $entry,
        string $drawId,
        int $winningHits,
        int $sequence,
        CarbonImmutable $completedAt,
        ?int $actorUserId,
    ): void {
        // Revalidate the aggregate one more time — the row was lockForUpdate'd,
        // but defensive equality here surfaces any miswired data immediately.
        if ($entry->status !== EntryStatus::Confirmed) {
            throw GameParticipationIntegrityViolation::withContext(
                'Entry is not Confirmed at the moment of winner resolution.',
                ['entry_id' => $entry->id, 'entry_status' => $entry->status->value],
            );
        }
        if ($entry->game_id !== $game->id || $entry->game_number_id !== $gameNumber->id) {
            throw GameParticipationIntegrityViolation::withContext(
                'Entry does not belong to the game/number being resolved.',
                ['entry_id' => $entry->id, 'game_id' => $game->id, 'game_number_id' => $gameNumber->id],
            );
        }
        if ($gameNumber->status !== GameNumberStatus::Sold) {
            throw GameParticipationIntegrityViolation::withContext(
                'GameNumber is not Sold at winner resolution.',
                ['game_number_id' => $gameNumber->id, 'status' => $gameNumber->status->value],
            );
        }

        $entry->transitionTo(EntryStatus::Winner);
        $entry->save();

        $winner = GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $drawId,
            'game_number_id' => $gameNumber->id,
            'user_id' => $entry->user_id,
            'winning_hits' => $winningHits,
            'won_at' => $completedAt,
        ]);

        $game->transitionTo(GameStatus::Resolving);
        $game->save();
        $game->transitionTo(GameStatus::Completed);
        $game->completed_at = $completedAt;
        $game->next_draw_at = null;
        $game->save();

        $totalDraws = (int) DB::table('game_draws')->where('game_id', $game->id)->count();

        GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::WinningNumberDetected,
            'payload' => [
                'draw_id' => $drawId,
                'game_number_id' => $gameNumber->id,
                'number' => (int) $gameNumber->number,
                'sequence' => $sequence,
                'hits_count' => $winningHits,
                'hits_required' => $game->hits_required,
                'game_entry_id' => $entry->id,
            ],
            'actor_user_id' => $actorUserId,
            'occurred_at' => $completedAt,
        ]);

        GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::WinnerDeclared,
            'payload' => [
                'winner_id' => $winner->id,
                'game_entry_id' => $entry->id,
                'game_number_id' => $gameNumber->id,
                'game_draw_id' => $drawId,
                'user_id' => $entry->user_id,
                'winning_hits' => $winningHits,
                'won_at' => $completedAt->toIso8601String(),
            ],
            'actor_user_id' => $actorUserId,
            'occurred_at' => $completedAt,
        ]);

        GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::GameCompleted,
            'payload' => [
                'winner_id' => $winner->id,
                'game_draw_id' => $drawId,
                'completed_at' => $completedAt->toIso8601String(),
                'total_draws' => $totalDraws,
            ],
            'actor_user_id' => $actorUserId,
            'occurred_at' => $completedAt,
        ]);
    }

    /**
     * Validate the GameNumber.status ↔ GameEntry relationship and report
     * whether the drawn number counts as sold (i.e. backed by a Confirmed
     * entry that belongs to this game / number / user).
     */
    private function validateParticipation(Game $game, GameNumber $gameNumber, ?GameEntry $entry): bool
    {
        if ($gameNumber->status === GameNumberStatus::Reserved) {
            throw GameParticipationIntegrityViolation::withContext(
                'GameNumber is reserved while game is running.',
                ['game_id' => $game->id, 'game_number_id' => $gameNumber->id],
            );
        }

        if ($gameNumber->status === GameNumberStatus::Available) {
            if ($entry !== null) {
                throw GameParticipationIntegrityViolation::withContext(
                    'GameNumber is available but a GameEntry exists.',
                    ['game_number_id' => $gameNumber->id, 'entry_status' => $entry->status->value],
                );
            }

            return false;
        }

        // Sold: must have exactly one Confirmed entry belonging to this aggregate.
        if ($entry === null) {
            throw GameParticipationIntegrityViolation::withContext(
                'GameNumber is sold but no GameEntry was found.',
                ['game_id' => $game->id, 'game_number_id' => $gameNumber->id],
            );
        }
        if ($entry->status !== EntryStatus::Confirmed) {
            throw GameParticipationIntegrityViolation::withContext(
                'GameNumber is sold but GameEntry status is not confirmed.',
                ['game_number_id' => $gameNumber->id, 'entry_status' => $entry->status->value],
            );
        }
        if ($entry->game_id !== $game->id || $entry->game_number_id !== $gameNumber->id) {
            throw GameParticipationIntegrityViolation::withContext(
                'GameEntry does not belong to the same aggregate as the drawn number.',
                ['game_id' => $game->id, 'game_number_id' => $gameNumber->id, 'entry_game_id' => $entry->game_id],
            );
        }

        return true;
    }
}
