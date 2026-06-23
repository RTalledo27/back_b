<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Modules\RepeatNumberBingo\Application\Actions\DispatchDueGameDrawsAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ExecuteScheduledGameDrawAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\Jobs\DispatchDueGameDrawsJob;
use App\Modules\RepeatNumberBingo\Application\Jobs\ExecuteScheduledGameDrawJob;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineTickCommandIdGenerator;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

final class ExecuteScheduledGameDrawJobTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGame(): Game
    {
        $scheduledAt = CarbonImmutable::now()->startOfSecond()->subSeconds(5);
        $game = Game::create([
            'slug' => 'sj-'.fake()->unique()->lexify('?????'),
            'name' => 'Scheduled job',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
            'scheduled_start_at' => $scheduledAt->subHour(),
            'started_at' => $scheduledAt->subMinutes(10),
            'next_draw_at' => $scheduledAt,
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

    private function tick(Game $game): EngineTick
    {
        $scheduledAt = $game->next_draw_at->toImmutable();

        return new EngineTick(
            gameId: $game->id,
            scheduledAt: $scheduledAt,
            commandId: app(EngineTickCommandIdGenerator::class)->generate($game->id, $scheduledAt),
        );
    }

    public function test_job_contract_uses_command_uniqueness_game_overlap_and_backoff(): void
    {
        $job = new ExecuteScheduledGameDrawJob($this->tick($this->makeGame()));

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame($job->tick->commandId->toString(), $job->uniqueId());
        $this->assertGreaterThan(1, $job->tries);
        $this->assertSame([1, 5, 10], $job->backoff());
        $this->assertCount(1, $job->middleware());
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
    }

    public function test_duplicate_job_execution_does_not_duplicate_draw(): void
    {
        $game = $this->makeGame();
        $tick = $this->tick($game);
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([3]));
        $action = app(ExecuteScheduledGameDrawAction::class);
        $job = new ExecuteScheduledGameDrawJob($tick);

        app()->call([$job, 'handle'], ['action' => $action]);
        app()->call([$job, 'handle'], ['action' => $action]);

        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
    }

    public function test_success_telemetry_contains_structured_engine_context(): void
    {
        Log::spy();
        $game = $this->makeGame();
        $tick = $this->tick($game);
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([3]));

        app()->call([new ExecuteScheduledGameDrawJob($tick), 'handle']);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'engine.scheduled_draw.completed'
                && $context['game_id'] === $game->id
                && $context['command_id'] === $tick->commandId->toString()
                && $context['outcome'] === 'executed'
                && array_key_exists('duration_ms', $context)
                && array_key_exists('skipped_ticks', $context))
            ->once();
    }

    public function test_dispatcher_job_dispatches_one_child_job_per_tick(): void
    {
        Queue::fake();
        $game = $this->makeGame();

        app(DispatchDueGameDrawsJob::class)->handle(
            app(DispatchDueGameDrawsAction::class),
        );

        Queue::assertPushed(ExecuteScheduledGameDrawJob::class, function (ExecuteScheduledGameDrawJob $job) use ($game): bool {
            return $job->tick->gameId === $game->id;
        });
    }

    public function test_scheduler_registers_engine_dispatcher_with_supported_frequency(): void
    {
        $pollSeconds = (int) config('engine.dispatch_poll_seconds');
        DispatchDueGameDrawsJob::validatePollSeconds($pollSeconds);

        $this->artisan('schedule:list')
            ->expectsOutputToContain('DispatchDueGameDrawsJob')
            ->assertSuccessful();
        $this->assertContains($pollSeconds, DispatchDueGameDrawsJob::VALID_POLL_SECONDS);
    }

    public function test_expected_skip_outcome_does_not_throw_or_create_failed_job(): void
    {
        $game = $this->makeGame();
        $tick = $this->tick($game);
        $game->status = GameStatus::Paused;
        $game->paused_at = now();
        $game->next_draw_at = null;
        $game->saveQuietly();

        $job = new ExecuteScheduledGameDrawJob($tick);
        app()->call([$job, 'handle']);

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(0, GameDraw::query()->where('game_id', $game->id)->count());
    }
}
