<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\ExecuteScheduledGameDrawAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\ExecuteScheduledGameDrawOutcome;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameNumberDrawn;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineTickCommandIdGenerator;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

final class ExecuteScheduledGameDrawActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGame(int $hitsRequired = 5): Game
    {
        $nextDrawAt = CarbonImmutable::now()->startOfSecond()->subSeconds(5);

        $game = Game::create([
            'slug' => 'sd-'.fake()->unique()->lexify('?????'),
            'name' => 'Scheduled Draw',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => $hitsRequired,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
            'scheduled_start_at' => $nextDrawAt->subHour(),
            'started_at' => $nextDrawAt->subMinutes(10),
            'next_draw_at' => $nextDrawAt,
        ]);

        for ($number = 1; $number <= 5; $number++) {
            GameNumber::create([
                'game_id' => $game->id,
                'number' => $number,
                'status' => GameNumberStatus::Available,
            ]);
        }

        return $game;
    }

    private function sellNumber(Game $game, int $number): void
    {
        $gameNumber = GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', $number)
            ->firstOrFail();
        $gameNumber->status = GameNumberStatus::Sold;
        $gameNumber->save();

        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gameNumber->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    private function tick(Game $game, ?CarbonImmutable $scheduledAt = null): EngineTick
    {
        $scheduledAt ??= $game->next_draw_at->toImmutable();

        return new EngineTick(
            gameId: $game->id,
            scheduledAt: $scheduledAt,
            commandId: app(EngineTickCommandIdGenerator::class)->generate($game->id, $scheduledAt),
        );
    }

    /** @param list<int> $numbers */
    private function action(array $numbers): ExecuteScheduledGameDrawAction
    {
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy($numbers));

        return app(ExecuteScheduledGameDrawAction::class);
    }

    public function test_fresh_tick_creates_draw_and_advances_calendar(): void
    {
        Event::fake([GameNumberDrawn::class]);
        $game = $this->makeGame();
        $tick = $this->tick($game);

        $result = $this->action([3])->execute($tick);

        $this->assertSame(ExecuteScheduledGameDrawOutcome::Executed, $result->outcome);
        $this->assertNotNull($result->drawResult);
        $this->assertFalse($result->drawResult->wasReplay);
        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());

        $game->refresh();
        $this->assertTrue($game->last_consumed_tick_at->toImmutable()->equalTo($tick->scheduledAt));
        $this->assertTrue($game->next_draw_at->toImmutable()->equalTo($tick->scheduledAt->addSeconds(30)));
        Event::assertDispatched(GameNumberDrawn::class, 1);
    }

    public function test_delayed_tick_skips_to_first_future_grid_point_and_aggregates_audit(): void
    {
        $now = CarbonImmutable::parse('2026-06-23 10:02:10');
        CarbonImmutable::setTestNow($now);

        try {
            $game = $this->makeGame();
            $game->next_draw_at = CarbonImmutable::parse('2026-06-23 10:00:30');
            $game->started_at = CarbonImmutable::parse('2026-06-23 09:50:00');
            $game->saveQuietly();
            $tick = $this->tick($game);

            $result = $this->action([3])->execute($tick);

            $this->assertSame(3, $result->skippedTicks);
            $this->assertTrue(
                $result->nextDrawAt?->equalTo(CarbonImmutable::parse('2026-06-23 10:02:30')),
            );

            $audit = GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::EngineTicksSkipped)
                ->sole();

            $this->assertSame('skip_to_next', $audit->payload['policy']);
            $this->assertSame(3, $audit->payload['skipped_ticks']);
            $this->assertSame('2026-06-23T10:01:00+00:00', $audit->payload['first_skipped_at']);
            $this->assertSame('2026-06-23T10:02:00+00:00', $audit->payload['last_skipped_at']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_on_time_tick_does_not_create_skipped_ticks_audit(): void
    {
        $now = CarbonImmutable::parse('2026-06-23 10:00:35');
        CarbonImmutable::setTestNow($now);

        try {
            $game = $this->makeGame();
            $game->next_draw_at = CarbonImmutable::parse('2026-06-23 10:00:30');
            $game->saveQuietly();

            $result = $this->action([2])->execute($this->tick($game));

            $this->assertSame(0, $result->skippedTicks);
            $this->assertTrue(
                $result->nextDrawAt?->equalTo(CarbonImmutable::parse('2026-06-23 10:01:00')),
            );
            $this->assertSame(
                0,
                GameEvent::query()
                    ->where('game_id', $game->id)
                    ->where('type', GameEventType::EngineTicksSkipped)
                    ->count(),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_winning_draw_leaves_next_draw_at_null(): void
    {
        $game = $this->makeGame(hitsRequired: 2);
        $this->sellNumber($game, 1);
        $action = $this->action([1, 1]);

        $firstTick = $this->tick($game);
        $action->execute($firstTick);
        $game->refresh();

        $secondTick = $this->tick($game);
        $result = $action->execute($secondTick);

        $this->assertSame(ExecuteScheduledGameDrawOutcome::Executed, $result->outcome);
        $this->assertTrue($result->drawResult?->winnerCreated);
        $game->refresh();
        $this->assertSame(GameStatus::Completed, $game->status);
        $this->assertNull($game->next_draw_at);
        $this->assertTrue($game->last_consumed_tick_at->toImmutable()->equalTo($secondTick->scheduledAt));
    }

    public function test_same_tick_replays_without_duplicate_draw_calendar_or_events(): void
    {
        Event::fake([GameNumberDrawn::class]);
        $game = $this->makeGame();
        $tick = $this->tick($game);
        $action = $this->action([4]);

        $first = $action->execute($tick);
        $game->refresh();
        $nextDrawAt = $game->next_draw_at->toIso8601String();
        $second = $action->execute($tick);

        $this->assertSame(ExecuteScheduledGameDrawOutcome::Executed, $first->outcome);
        $this->assertSame(ExecuteScheduledGameDrawOutcome::Replayed, $second->outcome);
        $this->assertTrue($second->drawResult?->wasReplay);
        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame(1, DrawCommand::query()->where('game_id', $game->id)->count());
        $game->refresh();
        $this->assertSame($nextDrawAt, $game->next_draw_at->toIso8601String());
        Event::assertDispatched(GameNumberDrawn::class, 1);
    }

    public function test_stale_tick_is_obsolete_and_does_not_modify_state(): void
    {
        $game = $this->makeGame();
        $originalNextDrawAt = $game->next_draw_at->toIso8601String();
        $staleAt = $game->next_draw_at->toImmutable()->subSeconds(30);

        $result = $this->action([2])->execute($this->tick($game, $staleAt));

        $this->assertSame(ExecuteScheduledGameDrawOutcome::ObsoleteTick, $result->outcome);
        $this->assertSame(0, GameDraw::query()->where('game_id', $game->id)->count());
        $game->refresh();
        $this->assertSame($originalNextDrawAt, $game->next_draw_at->toIso8601String());
        $this->assertNull($game->last_consumed_tick_at);
    }

    public function test_paused_completed_and_disabled_are_clean_outcomes(): void
    {
        $paused = $this->makeGame();
        $pausedTick = $this->tick($paused);
        $paused->status = GameStatus::Paused;
        $paused->paused_at = now();
        $paused->next_draw_at = null;
        $paused->saveQuietly();

        $completed = $this->makeGame();
        $completedTick = $this->tick($completed);
        $completed->status = GameStatus::Completed;
        $completed->completed_at = now();
        $completed->next_draw_at = null;
        $completed->saveQuietly();

        $disabled = $this->makeGame();
        $disabledTick = $this->tick($disabled);
        $disabled->auto_draw_enabled = false;
        $disabled->saveQuietly();

        $action = $this->action([1]);

        $this->assertSame(ExecuteScheduledGameDrawOutcome::SkippedPaused, $action->execute($pausedTick)->outcome);
        $this->assertSame(ExecuteScheduledGameDrawOutcome::SkippedCompleted, $action->execute($completedTick)->outcome);
        $this->assertSame(ExecuteScheduledGameDrawOutcome::SkippedDisabled, $action->execute($disabledTick)->outcome);
        $this->assertSame(0, GameDraw::query()->count());
    }

    public function test_replay_with_unconsumed_tick_raises_integrity_exception(): void
    {
        $game = $this->makeGame();
        $tick = $this->tick($game);
        $action = $this->action([2]);
        $action->execute($tick);

        $game->last_consumed_tick_at = $tick->scheduledAt->subSecond();
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);
        $action->execute($tick);
    }
}
