<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersData;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersOutcome;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersResult;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameCountersRebuilt;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\RebuildIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Rebuild `game_number_counters` for a single game from the canonical
 * history (`game_draws`).
 *
 * Hard contract:
 *   - game_draws is the source of truth — NEVER modified here.
 *   - This Action only writes to game_number_counters.
 *   - DrawCommand, GameWinner, GameEntry, Game state are NEVER touched.
 *
 * Lock order:
 *   1. Game FOR UPDATE  (root mutex shared with Start/Draw)
 *   2. game_number_counters DELETE + bulk INSERT (only when the maps
 *      differ).
 *
 * Outcomes:
 *   - Rebuilt           — projection diverged and was replaced.
 *   - AlreadyConsistent — projection already matched the history; no
 *                         write, no audit, no event.
 */
final class RebuildGameNumberCountersAction
{
    public function execute(RebuildCountersData $data): RebuildCountersResult
    {
        $result = DB::transaction(
            fn (): RebuildCountersResult => $this->executeWithinTransaction($data),
        );

        if ($result->outcome === RebuildCountersOutcome::Rebuilt) {
            try {
                GameCountersRebuilt::dispatch(
                    $result->gameId,
                    $data->actorUserId,
                    $result->previousRows,
                    $result->rebuiltRows,
                    $result->totalDraws,
                    $result->rebuiltAt->toIso8601String(),
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    public function executeWithinTransaction(RebuildCountersData $data): RebuildCountersResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'RebuildGameNumberCountersAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        // 1. Root lock.
        /** @var ?Game $game */
        $game = Game::query()->whereKey($data->gameId)->lockForUpdate()->first();
        if ($game === null) {
            throw (new ModelNotFoundException)->setModel(Game::class, [$data->gameId]);
        }

        // 2. Read the canonical history. No locks on draws — game_draws
        //    is append-only and the Game lock serialises rebuild against
        //    any concurrent draw.
        $draws = DB::table('game_draws')
            ->where('game_id', $game->id)
            ->orderBy('sequence')
            ->get(['game_number_id', 'sequence']);

        $totalDraws = $draws->count();

        // 3. Basic history integrity (no holes, no zero/negatives, no
        //    impossibly large maxima).
        if ($totalDraws > 0) {
            $sequences = $draws->pluck('sequence')->map(fn ($s) => (int) $s)->all();
            $minSeq = min($sequences);
            $maxSeq = max($sequences);
            if ($minSeq !== 1) {
                throw RebuildIntegrityViolation::withContext(
                    'History does not start at sequence 1.',
                    ['game_id' => $game->id, 'min_sequence' => $minSeq],
                );
            }
            if ($maxSeq !== $totalDraws) {
                throw RebuildIntegrityViolation::withContext(
                    'History has gaps: MAX(sequence) != COUNT(*).',
                    ['game_id' => $game->id, 'max_sequence' => $maxSeq, 'total_draws' => $totalDraws],
                );
            }
        }

        $maxSequence = $totalDraws;

        // 4. Build the expected projection map.
        $expectedAggregates = DB::table('game_draws')
            ->where('game_id', $game->id)
            ->select(
                'game_number_id',
                DB::raw('COUNT(*) AS hits_count'),
                DB::raw('MAX(sequence) AS last_draw_sequence'),
            )
            ->groupBy('game_number_id')
            ->orderBy('game_number_id')
            ->get();

        /** @var array<string, array{hits_count: int, last_draw_sequence: int}> $expectedMap */
        $expectedMap = [];
        foreach ($expectedAggregates as $row) {
            $expectedMap[(string) $row->game_number_id] = [
                'hits_count' => (int) $row->hits_count,
                'last_draw_sequence' => (int) $row->last_draw_sequence,
            ];
        }
        ksort($expectedMap);

        $expectedHitsTotal = array_sum(array_column($expectedMap, 'hits_count'));
        if ($expectedHitsTotal !== $totalDraws) {
            throw RebuildIntegrityViolation::withContext(
                'Expected hits total does not equal total draws (impossible aggregation result).',
                ['game_id' => $game->id, 'expected_hits_total' => $expectedHitsTotal, 'total_draws' => $totalDraws],
            );
        }

        // 5. Read the current projection and normalise it identically.
        $currentRows = DB::table('game_number_counters')
            ->where('game_id', $game->id)
            ->orderBy('game_number_id')
            ->get(['game_number_id', 'hits_count', 'last_draw_sequence']);

        /** @var array<string, array{hits_count: int, last_draw_sequence: int}> $currentMap */
        $currentMap = [];
        foreach ($currentRows as $row) {
            $currentMap[(string) $row->game_number_id] = [
                'hits_count' => (int) $row->hits_count,
                'last_draw_sequence' => (int) $row->last_draw_sequence,
            ];
        }
        ksort($currentMap);

        $previousRows = $currentRows->count();
        $previousHitsTotal = (int) array_sum(array_column($currentMap, 'hits_count'));

        // 6. Cross-aggregate integrity with Winner / game state. This must
        //    happen BEFORE deciding to rebuild — rebuild must not paper
        //    over a corrupt aggregate.
        $this->assertLifecycleConsistency($game, $totalDraws);
        $this->assertWinnerAndGameStateConsistency($game, $expectedMap, $maxSequence, $draws);

        // 7. Comparison decides the branch.
        $rebuiltAt = CarbonImmutable::now();

        if ($currentMap === $expectedMap) {
            return new RebuildCountersResult(
                gameId: $game->id,
                previousRows: $previousRows,
                previousHitsTotal: $previousHitsTotal,
                rebuiltRows: $previousRows,
                rebuiltHitsTotal: $previousHitsTotal,
                totalDraws: $totalDraws,
                maxSequence: $maxSequence,
                rebuiltAt: $rebuiltAt,
                outcome: RebuildCountersOutcome::AlreadyConsistent,
            );
        }

        // 8. Replace the projection.
        DB::table('game_number_counters')->where('game_id', $game->id)->delete();

        if ($expectedMap !== []) {
            $now = $rebuiltAt;
            $rows = [];
            foreach ($expectedMap as $gameNumberId => $aggregate) {
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'game_id' => $game->id,
                    'game_number_id' => $gameNumberId,
                    'hits_count' => $aggregate['hits_count'],
                    'last_draw_sequence' => $aggregate['last_draw_sequence'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            // Chunked bulk insert keeps statement length manageable on
            // very wide ranges. 500 rows ≈ ~80 KB of SQL.
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('game_number_counters')->insert($chunk);
            }
        }

        // 9. Reread + re-verify strict equality and the hits-total sum.
        $rebuiltRows = DB::table('game_number_counters')
            ->where('game_id', $game->id)
            ->orderBy('game_number_id')
            ->get(['game_number_id', 'hits_count', 'last_draw_sequence']);

        /** @var array<string, array{hits_count: int, last_draw_sequence: int}> $rebuiltMap */
        $rebuiltMap = [];
        foreach ($rebuiltRows as $row) {
            $rebuiltMap[(string) $row->game_number_id] = [
                'hits_count' => (int) $row->hits_count,
                'last_draw_sequence' => (int) $row->last_draw_sequence,
            ];
        }
        ksort($rebuiltMap);

        if ($rebuiltMap !== $expectedMap) {
            throw RebuildIntegrityViolation::withContext(
                'Rebuilt projection diverges from the expected map.',
                ['game_id' => $game->id],
            );
        }
        $rebuiltHitsTotal = (int) array_sum(array_column($rebuiltMap, 'hits_count'));
        if ($rebuiltHitsTotal !== $totalDraws) {
            throw RebuildIntegrityViolation::withContext(
                'Rebuilt hits total does not equal total draws.',
                ['game_id' => $game->id, 'rebuilt_hits_total' => $rebuiltHitsTotal, 'total_draws' => $totalDraws],
            );
        }

        // 10. Audit only on Rebuilt.
        GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::CountersRebuilt,
            'payload' => [
                'actor_user_id' => $data->actorUserId,
                'previous_rows' => $previousRows,
                'previous_hits_total' => $previousHitsTotal,
                'rebuilt_rows' => $rebuiltRows->count(),
                'rebuilt_hits_total' => $rebuiltHitsTotal,
                'total_draws' => $totalDraws,
                'max_sequence' => $maxSequence,
                'rebuilt_at' => $rebuiltAt->toIso8601String(),
            ],
            'actor_user_id' => $data->actorUserId,
            'occurred_at' => $rebuiltAt,
        ]);

        return new RebuildCountersResult(
            gameId: $game->id,
            previousRows: $previousRows,
            previousHitsTotal: $previousHitsTotal,
            rebuiltRows: $rebuiltRows->count(),
            rebuiltHitsTotal: $rebuiltHitsTotal,
            totalDraws: $totalDraws,
            maxSequence: $maxSequence,
            rebuiltAt: $rebuiltAt,
            outcome: RebuildCountersOutcome::Rebuilt,
        );
    }

    /**
     * Lifecycle-level invariants: timestamps, draws and game status must
     * make sense together. Rebuild must NEVER legitimise a history that
     * predates `started_at` or postdates `completed_at`, nor a row that
     * sits in the transient `Resolving` state outside a draw transaction.
     */
    private function assertLifecycleConsistency(Game $game, int $totalDraws): void
    {
        $minDrawnAt = null;
        $maxDrawnAt = null;
        if ($totalDraws > 0) {
            $row = DB::table('game_draws')
                ->where('game_id', $game->id)
                ->selectRaw('MIN(drawn_at) AS min_drawn_at, MAX(drawn_at) AS max_drawn_at')
                ->first();
            $minDrawnAt = $row?->min_drawn_at !== null ? CarbonImmutable::parse((string) $row->min_drawn_at) : null;
            $maxDrawnAt = $row?->max_drawn_at !== null ? CarbonImmutable::parse((string) $row->max_drawn_at) : null;
        }

        $startedAt = $game->started_at?->toImmutable();
        $completedAt = $game->completed_at?->toImmutable();

        switch ($game->status) {
            case GameStatus::Draft:
            case GameStatus::Published:
            case GameStatus::SalesOpen:
            case GameStatus::SalesClosed:
                if ($startedAt !== null) {
                    throw RebuildIntegrityViolation::withContext(
                        'Pre-start status carries a started_at timestamp.',
                        ['game_id' => $game->id, 'status' => $game->status->value],
                    );
                }
                if ($completedAt !== null) {
                    throw RebuildIntegrityViolation::withContext(
                        'Pre-start status carries a completed_at timestamp.',
                        ['game_id' => $game->id, 'status' => $game->status->value],
                    );
                }
                if ($totalDraws > 0) {
                    throw RebuildIntegrityViolation::withContext(
                        'Draws exist before the game was started.',
                        ['game_id' => $game->id, 'status' => $game->status->value, 'total_draws' => $totalDraws],
                    );
                }
                break;

            case GameStatus::Running:
                if ($startedAt === null) {
                    throw RebuildIntegrityViolation::withContext(
                        'Game is running but started_at is null.',
                        ['game_id' => $game->id],
                    );
                }
                if ($completedAt !== null) {
                    throw RebuildIntegrityViolation::withContext(
                        'Game is running but completed_at is already set.',
                        ['game_id' => $game->id],
                    );
                }
                if ($minDrawnAt !== null && $minDrawnAt->lessThan($startedAt)) {
                    throw RebuildIntegrityViolation::withContext(
                        'A Draw is older than started_at.',
                        ['game_id' => $game->id, 'min_drawn_at' => $minDrawnAt->toIso8601String(), 'started_at' => $startedAt->toIso8601String()],
                    );
                }
                break;

            case GameStatus::Paused:
                // Pause rules are not fully defined in Phase 3. Enforce
                // the minimum every other branch already requires: the
                // game was started, never completed, and any draws fall
                // after started_at. Full semantics arrive with the pause
                // feature.
                if ($startedAt === null) {
                    throw RebuildIntegrityViolation::withContext(
                        'Game is paused but started_at is null.',
                        ['game_id' => $game->id],
                    );
                }
                if ($completedAt !== null) {
                    throw RebuildIntegrityViolation::withContext(
                        'Game is paused but completed_at is set.',
                        ['game_id' => $game->id],
                    );
                }
                if ($minDrawnAt !== null && $minDrawnAt->lessThan($startedAt)) {
                    throw RebuildIntegrityViolation::withContext(
                        'A Draw predates started_at on a paused game.',
                        ['game_id' => $game->id],
                    );
                }
                break;

            case GameStatus::Resolving:
                // Phase 3.6 treats Resolving as an in-flight transition
                // inside the winning draw transaction. A row persisted in
                // this state is not something rebuild can legitimately
                // observe — surface it as corruption.
                throw RebuildIntegrityViolation::withContext(
                    'Game persisted in resolving state cannot be rebuilt.',
                    ['game_id' => $game->id],
                );

            case GameStatus::Completed:
                if ($startedAt === null) {
                    throw RebuildIntegrityViolation::withContext(
                        'Game is completed but started_at is null.',
                        ['game_id' => $game->id],
                    );
                }
                if ($completedAt === null) {
                    throw RebuildIntegrityViolation::withContext(
                        'Game is completed but completed_at is null.',
                        ['game_id' => $game->id],
                    );
                }
                if ($minDrawnAt !== null && $minDrawnAt->lessThan($startedAt)) {
                    throw RebuildIntegrityViolation::withContext(
                        'A Draw is older than started_at on a completed game.',
                        ['game_id' => $game->id],
                    );
                }
                if ($maxDrawnAt !== null && $maxDrawnAt->greaterThan($completedAt)) {
                    throw RebuildIntegrityViolation::withContext(
                        'A Draw is newer than completed_at.',
                        ['game_id' => $game->id, 'max_drawn_at' => $maxDrawnAt->toIso8601String(), 'completed_at' => $completedAt->toIso8601String()],
                    );
                }
                break;

            case GameStatus::Cancelled:
                // A cancelled game never carries a completion timestamp.
                if ($completedAt !== null) {
                    throw RebuildIntegrityViolation::withContext(
                        'Cancelled game carries a completed_at timestamp.',
                        ['game_id' => $game->id],
                    );
                }
                if ($startedAt === null) {
                    // Cancelled before being started — must have no history.
                    if ($totalDraws > 0) {
                        throw RebuildIntegrityViolation::withContext(
                            'Cancelled game was never started but draws exist.',
                            ['game_id' => $game->id, 'total_draws' => $totalDraws],
                        );
                    }
                } else {
                    // Cancelled after start — draws may exist but must
                    // post-date started_at. No completion timestamp; the
                    // cross-winner check guarantees no winner.
                    if ($minDrawnAt !== null && $minDrawnAt->lessThan($startedAt)) {
                        throw RebuildIntegrityViolation::withContext(
                            'Cancelled game has a Draw older than started_at.',
                            ['game_id' => $game->id, 'min_drawn_at' => $minDrawnAt->toIso8601String()],
                        );
                    }
                }
                break;
        }
    }

    /**
     * Cross-aggregate invariants. Rebuild must never silently repair a
     * corrupt winner or game state.
     *
     * @param  array<string, array{hits_count: int, last_draw_sequence: int}>  $expectedMap
     * @param  Collection<int, \stdClass>  $draws  ordered by sequence
     */
    private function assertWinnerAndGameStateConsistency(
        Game $game,
        array $expectedMap,
        int $maxSequence,
        Collection $draws,
    ): void {
        $winner = GameWinner::query()->where('game_id', $game->id)->first();

        // Running must NOT have a winner.
        if ($game->status === GameStatus::Running && $winner !== null) {
            throw RebuildIntegrityViolation::withContext(
                'Game is running but a winner already exists.',
                ['game_id' => $game->id],
            );
        }

        // Completed must have exactly one winner.
        if ($game->status === GameStatus::Completed && $winner === null) {
            throw RebuildIntegrityViolation::withContext(
                'Game is completed but no winner was found.',
                ['game_id' => $game->id],
            );
        }

        if ($winner === null) {
            return;
        }

        // Winner present — exhaustively verify aggregate coherence.
        if ($game->status !== GameStatus::Completed) {
            throw RebuildIntegrityViolation::withContext(
                'Winner exists but game is not completed.',
                ['game_id' => $game->id, 'status' => $game->status->value],
            );
        }
        if ($game->completed_at === null) {
            throw RebuildIntegrityViolation::withContext(
                'Game completed but completed_at is null.',
                ['game_id' => $game->id],
            );
        }

        // Winner.game_draw_id must exist in the history and must be the
        // very last draw (max sequence).
        $winningDraw = $draws->firstWhere('sequence', $maxSequence);
        if ($winningDraw === null) {
            throw RebuildIntegrityViolation::withContext(
                'Winner exists but the history has no draws.',
                ['game_id' => $game->id],
            );
        }
        $winningDrawRow = DB::table('game_draws')
            ->where('id', $winner->game_draw_id)
            ->first(['id', 'game_id', 'game_number_id', 'sequence', 'drawn_at']);
        if ($winningDrawRow === null || (int) $winningDrawRow->sequence !== $maxSequence) {
            throw RebuildIntegrityViolation::withContext(
                'Winner draw is not the final draw of the history.',
                ['game_id' => $game->id, 'winner_draw_id' => $winner->game_draw_id, 'max_sequence' => $maxSequence],
            );
        }
        if ((string) $winningDrawRow->game_number_id !== (string) $winner->game_number_id) {
            throw RebuildIntegrityViolation::withContext(
                'Winner draw game_number_id does not match winner game_number_id.',
                ['game_id' => $game->id],
            );
        }

        // Expected hits for the winning number must equal winning_hits AND
        // game.hits_required.
        $winningNumberAggregate = $expectedMap[(string) $winner->game_number_id] ?? null;
        if ($winningNumberAggregate === null) {
            throw RebuildIntegrityViolation::withContext(
                'Winning number has no aggregated history.',
                ['game_id' => $game->id, 'game_number_id' => $winner->game_number_id],
            );
        }
        if (
            $winningNumberAggregate['hits_count'] !== (int) $winner->winning_hits
            || $winningNumberAggregate['hits_count'] !== $game->hits_required
        ) {
            throw RebuildIntegrityViolation::withContext(
                'Winning number history hits do not match winner.winning_hits and game.hits_required.',
                [
                    'game_id' => $game->id,
                    'history_hits' => $winningNumberAggregate['hits_count'],
                    'winner_hits' => (int) $winner->winning_hits,
                    'hits_required' => $game->hits_required,
                ],
            );
        }

        // Referenced entry must be in Winner state.
        $entryStatus = (string) DB::table('game_entries')
            ->where('id', $winner->game_entry_id)->value('status');
        if ($entryStatus !== EntryStatus::Winner->value) {
            throw RebuildIntegrityViolation::withContext(
                'Winner entry is not in Winner state.',
                ['game_id' => $game->id, 'entry_id' => $winner->game_entry_id, 'entry_status' => $entryStatus],
            );
        }

        // won_at must equal completed_at and be >= winning draw drawn_at.
        if (! $winner->won_at->equalTo($game->completed_at)) {
            throw RebuildIntegrityViolation::withContext(
                'Winner.won_at does not equal Game.completed_at.',
                [
                    'game_id' => $game->id,
                    'won_at' => $winner->won_at->toIso8601String(),
                    'completed_at' => $game->completed_at->toIso8601String(),
                ],
            );
        }
        $winningDrawnAt = $winningDrawRow?->drawn_at !== null
            ? CarbonImmutable::parse((string) $winningDrawRow->drawn_at)
            : null;
        if ($winningDrawnAt !== null && $winner->won_at->lessThan($winningDrawnAt)) {
            throw RebuildIntegrityViolation::withContext(
                'Winner.won_at predates the winning draw drawn_at.',
                ['game_id' => $game->id],
            );
        }
    }
}
