<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberResult;
use App\Modules\RepeatNumberBingo\Application\DTOs\ExecuteScheduledGameDrawOutcome;
use App\Modules\RepeatNumberBingo\Application\DTOs\ExecuteScheduledGameDrawResult;
use App\Modules\RepeatNumberBingo\Application\Services\CommittedDrawEventsDispatcher;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameEngineConfiguration;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineGridCalculator;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ExecuteScheduledGameDrawAction
{
    public function __construct(
        private readonly DrawGameNumberAction $draw,
        private readonly EngineGridCalculator $grid,
        private readonly CommittedDrawEventsDispatcher $events,
    ) {}

    public function execute(EngineTick $tick): ExecuteScheduledGameDrawResult
    {
        $result = DB::transaction(
            fn (): ExecuteScheduledGameDrawResult => $this->executeWithinTransaction($tick),
        );

        if (
            $result->outcome === ExecuteScheduledGameDrawOutcome::Executed
            && $result->drawResult !== null
        ) {
            $this->events->dispatch($result->drawResult, $tick->commandId->toString());
        }

        return $result;
    }

    private function executeWithinTransaction(EngineTick $tick): ExecuteScheduledGameDrawResult
    {
        /** @var ?Game $game */
        $game = Game::query()
            ->whereKey($tick->gameId)
            ->lockForUpdate()
            ->first();

        if ($game === null) {
            throw (new ModelNotFoundException)->setModel(Game::class, [$tick->gameId]);
        }

        $command = DrawCommand::query()
            ->where('game_id', $game->id)
            ->where('command_id', $tick->commandId->toString())
            ->first();

        if ($command !== null) {
            return $this->replayResult($game, $tick, $command->result_payload);
        }

        $skippedOutcome = $this->classifySkippedOutcome($game, $tick);

        if ($skippedOutcome !== null) {
            return $this->resultFor($game, $tick, $skippedOutcome);
        }

        $drawResult = $this->draw->executeWithinTransaction(
            new DrawGameNumberData(
                gameId: $game->id,
                commandId: $tick->commandId,
                actorUserId: null,
            ),
            automated: true,
            lockedGame: $game,
        );

        if ($drawResult->wasReplay) {
            return $this->replayResultFromDraw($game, $tick, $drawResult);
        }

        $now = CarbonImmutable::now();
        $nextDrawAt = null;
        $skippedTicks = 0;

        if (! $drawResult->winnerCreated) {
            $this->assertSkipToNextPolicy();

            $nextDrawAt = $this->grid->skipToNext(
                $tick->scheduledAt,
                $game->draw_interval_seconds,
                $now,
            );
            $firstSkippedAt = $this->grid->advanceAfter(
                $tick->scheduledAt,
                $game->draw_interval_seconds,
            );
            $skippedTicks = $this->grid->countSkippedBetween(
                $firstSkippedAt,
                $nextDrawAt,
                $game->draw_interval_seconds,
            );

            if ($skippedTicks > 0) {
                GameEvent::create([
                    'game_id' => $game->id,
                    'type' => GameEventType::EngineTicksSkipped,
                    'payload' => [
                        'policy' => 'skip_to_next',
                        'command_id' => $tick->commandId->toString(),
                        'consumed_tick_at' => $tick->scheduledAt->toIso8601String(),
                        'first_skipped_at' => $firstSkippedAt->toIso8601String(),
                        'last_skipped_at' => $nextDrawAt
                            ->subSeconds($game->draw_interval_seconds)
                            ->toIso8601String(),
                        'next_draw_at' => $nextDrawAt->toIso8601String(),
                        'skipped_ticks' => $skippedTicks,
                    ],
                    'actor_user_id' => null,
                    'occurred_at' => $now,
                ]);
            }
        }

        $game->last_consumed_tick_at = $tick->scheduledAt;
        $game->next_draw_at = $nextDrawAt;
        $game->save();

        return new ExecuteScheduledGameDrawResult(
            gameId: $game->id,
            scheduledAt: $tick->scheduledAt,
            outcome: ExecuteScheduledGameDrawOutcome::Executed,
            drawResult: $drawResult,
            lastConsumedTickAt: $tick->scheduledAt,
            nextDrawAt: $game->next_draw_at?->toImmutable(),
            skippedTicks: $skippedTicks,
        );
    }

    private function classifySkippedOutcome(
        Game $game,
        EngineTick $tick,
    ): ?ExecuteScheduledGameDrawOutcome {
        if ($game->status === GameStatus::Paused) {
            return ExecuteScheduledGameDrawOutcome::SkippedPaused;
        }

        if ($game->status === GameStatus::Completed) {
            return ExecuteScheduledGameDrawOutcome::SkippedCompleted;
        }

        if (! $game->auto_draw_enabled) {
            return ExecuteScheduledGameDrawOutcome::SkippedDisabled;
        }

        if (
            $game->status !== GameStatus::Running
            || $game->next_draw_at === null
            || ! $game->next_draw_at->toImmutable()->equalTo($tick->scheduledAt)
        ) {
            return ExecuteScheduledGameDrawOutcome::ObsoleteTick;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayResult(
        Game $game,
        EngineTick $tick,
        array $payload,
    ): ExecuteScheduledGameDrawResult {
        return $this->replayResultFromDraw(
            $game,
            $tick,
            DrawGameNumberResult::fromArray($payload, asReplay: true),
        );
    }

    private function replayResultFromDraw(
        Game $game,
        EngineTick $tick,
        DrawGameNumberResult $drawResult,
    ): ExecuteScheduledGameDrawResult {
        if (
            $game->last_consumed_tick_at === null
            || $game->last_consumed_tick_at->toImmutable()->lessThan($tick->scheduledAt)
        ) {
            throw GameLifecycleIntegrityViolation::withContext(
                'DrawCommand exists but the engine tick was not recorded as consumed.',
                [
                    'game_id' => $game->id,
                    'command_id' => $tick->commandId->toString(),
                    'scheduled_at' => $tick->scheduledAt->toIso8601String(),
                    'last_consumed_tick_at' => $game->last_consumed_tick_at?->toIso8601String(),
                ],
            );
        }

        return new ExecuteScheduledGameDrawResult(
            gameId: $game->id,
            scheduledAt: $tick->scheduledAt,
            outcome: ExecuteScheduledGameDrawOutcome::Replayed,
            drawResult: $drawResult,
            lastConsumedTickAt: $game->last_consumed_tick_at->toImmutable(),
            nextDrawAt: $game->next_draw_at?->toImmutable(),
        );
    }

    private function resultFor(
        Game $game,
        EngineTick $tick,
        ExecuteScheduledGameDrawOutcome $outcome,
    ): ExecuteScheduledGameDrawResult {
        return new ExecuteScheduledGameDrawResult(
            gameId: $game->id,
            scheduledAt: $tick->scheduledAt,
            outcome: $outcome,
            lastConsumedTickAt: $game->last_consumed_tick_at?->toImmutable(),
            nextDrawAt: $game->next_draw_at?->toImmutable(),
        );
    }

    private function assertSkipToNextPolicy(): void
    {
        $policy = (string) config('engine.catch_up_policy', 'skip_to_next');

        if ($policy !== 'skip_to_next') {
            throw InvalidGameEngineConfiguration::unsupportedCatchUpPolicy($policy);
        }
    }
}
