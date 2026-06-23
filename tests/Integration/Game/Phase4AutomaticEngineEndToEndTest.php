<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\DispatchDueGameDrawsAction;
use App\Modules\RepeatNumberBingo\Application\Actions\PauseGameAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ResumeGameAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\Contracts\PublicGameUpdatesPublisher;
use App\Modules\RepeatNumberBingo\Application\DTOs\PauseGameData;
use App\Modules\RepeatNumberBingo\Application\DTOs\PublicGameUpdateReason;
use App\Modules\RepeatNumberBingo\Application\DTOs\ResumeGameData;
use App\Modules\RepeatNumberBingo\Application\Jobs\DispatchDueGameDrawsJob;
use App\Modules\RepeatNumberBingo\Application\Jobs\ExecuteScheduledGameDrawJob;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameActionActor;
use App\Modules\RepeatNumberBingo\Infrastructure\Broadcasting\Events\PublicGameUpdated;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

final class Phase4AutomaticEngineEndToEndTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23T18:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_scheduler_to_job_persists_draw_advances_calendar_broadcasts_and_matches_rest_snapshot(): void
    {
        $game = $this->makeRunningGame();
        $this->useDrawSequence([3]);
        Event::fake([PublicGameUpdated::class]);

        $job = $this->dispatchDueJob();
        $this->runJob($job);

        $game->refresh();
        $draw = GameDraw::query()->where('game_id', $game->id)->sole();
        $this->assertSame(3, $draw->drawn_number);
        $this->assertTrue($game->last_consumed_tick_at->equalTo($job->tick->scheduledAt));
        $this->assertTrue($game->next_draw_at->greaterThan(CarbonImmutable::now()));
        $this->assertSame(1, DrawCommand::query()->where('game_id', $game->id)->count());

        $broadcast = $this->broadcastPayload();
        $rest = $this->getJson("/api/v1/public/games/{$game->slug}")
            ->assertOk()
            ->json('data');

        $this->assertSame($rest['status'], $broadcast['status']);
        $this->assertSame($rest['latest_draw'], $broadcast['latest_draw']);
        $this->assertSame($rest['schedule']['next_draw_at'], $broadcast['next_draw_at']);
        $this->assertSame($rest['winner'], $broadcast['winner']);
    }

    public function test_winning_automatic_draw_broadcasts_and_rest_expose_the_same_final_snapshot(): void
    {
        $game = $this->makeRunningGame(hitsRequired: 2);
        $number = $this->number($game, 1);
        $number->status = GameNumberStatus::Sold;
        $number->save();
        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now()->subHour(),
        ]);
        $historicalDraw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => 1,
            'drawn_number' => 1,
            'drawn_at' => now()->subMinute(),
            'strategy' => 'historical_test',
        ]);
        GameNumberCounter::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'hits_count' => 1,
            'last_draw_sequence' => $historicalDraw->sequence,
        ]);
        $this->useDrawSequence([1]);
        Event::fake([PublicGameUpdated::class]);

        $this->runJob($this->dispatchDueJob());

        $game->refresh();
        $this->assertSame(GameStatus::Completed, $game->status);
        $this->assertNull($game->next_draw_at);
        $this->assertSame(2, GameDraw::query()->where('game_id', $game->id)->count());

        $broadcast = $this->broadcastPayload();
        $rest = $this->getJson("/api/v1/public/games/{$game->slug}")
            ->assertOk()
            ->json('data');

        $this->assertSame('number_drawn', $broadcast['reason']);
        $this->assertSame('completed', $broadcast['status']);
        $this->assertSame($rest['latest_draw'], $broadcast['latest_draw']);
        $this->assertSame($rest['winner'], $broadcast['winner']);
        $this->assertNull($broadcast['next_draw_at']);
        Event::assertDispatched(PublicGameUpdated::class, 1);
    }

    public function test_replaying_the_same_tick_does_not_duplicate_draw_or_public_update(): void
    {
        $game = $this->makeRunningGame();
        $this->useDrawSequence([2]);
        Event::fake([PublicGameUpdated::class]);
        $job = $this->dispatchDueJob();

        $this->runJob($job);
        $this->runJob($job);

        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame(1, DrawCommand::query()->where('game_id', $game->id)->count());
        Event::assertDispatched(PublicGameUpdated::class, 1);
    }

    public function test_pause_blocks_dispatch_resume_restores_grid_and_next_tick_executes(): void
    {
        $game = $this->makeRunningGame();
        $admin = User::factory()->admin()->create();
        Event::fake([PublicGameUpdated::class]);

        app(PauseGameAction::class)->execute(new PauseGameData(
            $game->id,
            GameActionActor::admin($admin->id),
        ));

        Queue::fake([ExecuteScheduledGameDrawJob::class]);
        (new DispatchDueGameDrawsJob)->handle(app(DispatchDueGameDrawsAction::class));
        Queue::assertNotPushed(ExecuteScheduledGameDrawJob::class);

        $resume = app(ResumeGameAction::class)->execute(new ResumeGameData(
            $game->id,
            GameActionActor::admin($admin->id),
        ));
        CarbonImmutable::setTestNow($resume->nextDrawAt);
        $this->useDrawSequence([4]);

        $this->runJob($this->dispatchDueJob());

        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);
        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $this->getJson("/api/v1/public/games/{$game->slug}")
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.latest_draw.number', 4);
    }

    public function test_skip_to_next_creates_one_aggregate_audit_and_coherent_snapshot(): void
    {
        $game = $this->makeRunningGame(nextDrawAt: CarbonImmutable::now()->subSeconds(95));
        $this->useDrawSequence([5]);
        Event::fake([PublicGameUpdated::class]);

        $this->runJob($this->dispatchDueJob());

        $audit = GameEvent::query()
            ->where('game_id', $game->id)
            ->where('type', GameEventType::EngineTicksSkipped)
            ->sole();
        $game->refresh();

        $this->assertSame('skip_to_next', $audit->payload['policy']);
        $this->assertSame(3, $audit->payload['skipped_ticks']);
        $this->assertSame($game->next_draw_at->toIso8601String(), $audit->payload['next_draw_at']);
        $this->assertSame($game->next_draw_at->toIso8601String(), $this->broadcastPayload()['next_draw_at']);
    }

    public function test_integrity_auto_pause_can_recover_and_continue_through_the_full_flow(): void
    {
        $game = $this->makeRunningGame();
        $corruptNumber = $this->number($game, 3);
        $corruptNumber->status = GameNumberStatus::Sold;
        $corruptNumber->save();
        $this->useDrawSequence([3]);
        Event::fake([PublicGameUpdated::class]);

        $this->runJob($this->dispatchDueJob());

        $game->refresh();
        $this->assertSame(GameStatus::Paused, $game->status);
        $this->assertSame(
            1,
            GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::GameAutoPaused)
                ->count(),
        );
        $this->assertSame(0, GameDraw::query()->where('game_id', $game->id)->count());

        $corruptNumber->status = GameNumberStatus::Available;
        $corruptNumber->save();
        $resume = app(ResumeGameAction::class)->execute(new ResumeGameData(
            $game->id,
            GameActionActor::system(),
        ));
        CarbonImmutable::setTestNow($resume->nextDrawAt);
        $this->useDrawSequence([3]);

        $this->runJob($this->dispatchDueJob());

        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);
        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $this->getJson("/api/v1/public/games/{$game->slug}")
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.latest_draw.number', 3);
    }

    public function test_broadcast_failure_does_not_revert_automatic_draw_calendar_or_rest_state(): void
    {
        $game = $this->makeRunningGame();
        $this->useDrawSequence([2]);
        Exceptions::fake();
        $this->app->instance(PublicGameUpdatesPublisher::class, new class implements PublicGameUpdatesPublisher
        {
            public function publish(
                string $gameId,
                PublicGameUpdateReason $reason,
                CarbonImmutable $occurredAt,
            ): void {
                throw new RuntimeException('simulated broadcast outage');
            }
        });

        $this->runJob($this->dispatchDueJob());

        Exceptions::assertReported(RuntimeException::class);
        $game->refresh();
        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertNotNull($game->last_consumed_tick_at);
        $this->assertNotNull($game->next_draw_at);
        $this->getJson("/api/v1/public/games/{$game->slug}")
            ->assertOk()
            ->assertJsonPath('data.latest_draw.number', 2)
            ->assertJsonPath('data.schedule.next_draw_at', $game->next_draw_at->toIso8601String());
    }

    private function dispatchDueJob(): ExecuteScheduledGameDrawJob
    {
        Queue::fake([ExecuteScheduledGameDrawJob::class]);

        (new DispatchDueGameDrawsJob)->handle(app(DispatchDueGameDrawsAction::class));

        Queue::assertPushed(ExecuteScheduledGameDrawJob::class, 1);

        /** @var ExecuteScheduledGameDrawJob $job */
        $job = Queue::pushed(ExecuteScheduledGameDrawJob::class)->sole();

        return $job;
    }

    private function runJob(ExecuteScheduledGameDrawJob $job): void
    {
        app()->call([$job, 'handle']);
    }

    /**
     * @return array<string, mixed>
     */
    private function broadcastPayload(): array
    {
        $record = Event::dispatched(PublicGameUpdated::class)->sole();

        /** @var PublicGameUpdated $event */
        $event = is_array($record) ? $record[0] : $record;

        return $event->broadcastWith();
    }

    private function makeRunningGame(
        int $hitsRequired = 5,
        ?CarbonImmutable $nextDrawAt = null,
    ): Game {
        $nextDrawAt ??= CarbonImmutable::now()->subSeconds(5);
        $game = Game::create([
            'slug' => 'phase4-'.fake()->unique()->lexify('?????'),
            'name' => 'Phase 4 end-to-end',
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

    private function number(Game $game, int $number): GameNumber
    {
        return GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', $number)
            ->sole();
    }

    /**
     * @param  list<int>  $numbers
     */
    private function useDrawSequence(array $numbers): void
    {
        $this->app->instance(
            DrawNumberStrategy::class,
            new DeterministicDrawNumberStrategy($numbers),
        );
    }
}
