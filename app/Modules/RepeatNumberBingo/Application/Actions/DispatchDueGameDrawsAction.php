<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\DTOs\DispatchDueGameDrawsResult;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineTickCommandIdGenerator;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Selects all games whose next scheduled draw is overdue and builds one
 * EngineTick per game.
 *
 * Concurrency contract:
 *   1. Candidate IDs are snapshotted once outside any transaction using a
 *      deterministic ORDER BY (next_draw_at ASC, id ASC) bounded by
 *      engine.dispatch_batch_size.
 *   2. Each candidate is processed in its own short transaction with
 *      FOR UPDATE SKIP LOCKED so concurrent dispatcher instances skip rows
 *      held by another connection rather than blocking.
 *   3. SKIP LOCKED is NOT exactly-once across dispatchers. Once the short
 *      transaction commits and the row lock is released, another dispatcher
 *      can select the same game on the next poll. Exactly-once execution is
 *      provided in Block 4.5 via the UUID v5 deterministic commandId,
 *      ShouldBeUnique on ExecuteScheduledGameDrawJob, and the draw_commands
 *      unique constraint in PostgreSQL.
 *   4. Under the row lock, eligibility is revalidated (status, auto_draw_enabled,
 *      next_draw_at) to guard against state changes between snapshot and lock.
 *   5. No writes are performed — next_draw_at, status, and counters are
 *      untouched. ExecuteScheduledGameDrawJob (Block 4.5) owns those mutations.
 */
final class DispatchDueGameDrawsAction
{
    public function __construct(
        private readonly EngineTickCommandIdGenerator $generator,
    ) {}

    public function execute(): DispatchDueGameDrawsResult
    {
        $batchSize = (int) config('engine.dispatch_batch_size', 200);
        $now = CarbonImmutable::now();

        /** @var list<string> $candidateIds */
        $candidateIds = Game::query()
            ->where('status', GameStatus::Running->value)
            ->where('auto_draw_enabled', true)
            ->whereNotNull('next_draw_at')
            ->where('next_draw_at', '<=', $now)
            ->orderBy('next_draw_at')
            ->orderBy('id')
            ->limit($batchSize)
            ->pluck('id')
            ->all();

        $ticks = [];

        foreach ($candidateIds as $candidateId) {
            $tick = DB::transaction(function () use ($candidateId): ?EngineTick {
                $lockNow = CarbonImmutable::now();

                /** @var ?Game $game */
                $game = Game::query()
                    ->whereKey($candidateId)
                    ->lock('for update skip locked')
                    ->first();

                // Another dispatcher holds this row — skip without blocking.
                if ($game === null) {
                    return null;
                }

                // Revalidate under the lock: state may have changed since snapshot.
                if ($game->status !== GameStatus::Running) {
                    return null;
                }
                if (! $game->auto_draw_enabled) {
                    return null;
                }
                if ($game->next_draw_at === null) {
                    return null;
                }
                if ($game->next_draw_at->gt($lockNow)) {
                    return null;
                }

                $scheduledAt = CarbonImmutable::instance($game->next_draw_at);
                $commandId = $this->generator->generate($game->id, $scheduledAt);

                Log::info('engine.tick_selected', [
                    'game_id' => $game->id,
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                    'command_id' => $commandId->value,
                ]);

                return new EngineTick(
                    gameId: $game->id,
                    scheduledAt: $scheduledAt,
                    commandId: $commandId,
                );
            });

            if ($tick !== null) {
                $ticks[] = $tick;
            }
        }

        return new DispatchDueGameDrawsResult(
            ticks: $ticks,
            candidatesFound: count($candidateIds),
        );
    }
}
