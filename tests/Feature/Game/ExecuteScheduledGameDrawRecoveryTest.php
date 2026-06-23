<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Modules\RepeatNumberBingo\Application\Actions\AutoPauseGameAfterIntegrityFailureAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ResumeGameAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\AutoPauseGameOutcome;
use App\Modules\RepeatNumberBingo\Application\DTOs\ResumeGameData;
use App\Modules\RepeatNumberBingo\Application\Jobs\ExecuteScheduledGameDrawJob;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameParticipationIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineTickCommandIdGenerator;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameActionActor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

final class ExecuteScheduledGameDrawRecoveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_integrity_failure_auto_pauses_once_and_game_can_recover_after_fix(): void
    {
        Exceptions::fake();
        $game = $this->makeGame();
        $tick = $this->tick($game);
        $corruptedNumber = GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', 3)
            ->firstOrFail();
        $corruptedNumber->status = GameNumberStatus::Sold;
        $corruptedNumber->save();

        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([3]));
        $this->runJob($tick);
        $this->runJob($tick);

        $game->refresh();
        $this->assertSame(GameStatus::Paused, $game->status);
        $this->assertNull($game->next_draw_at);
        $this->assertSame(
            1,
            GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::GameAutoPaused)
                ->count(),
        );
        $this->assertDatabaseCount('failed_jobs', 0);
        Exceptions::assertReported(GameParticipationIntegrityViolation::class);

        $corruptedNumber->status = GameNumberStatus::Available;
        $corruptedNumber->save();

        $resume = app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::system(),
        ));

        $nextTick = new EngineTick(
            gameId: $game->id,
            scheduledAt: $resume->nextDrawAt,
            commandId: app(EngineTickCommandIdGenerator::class)->generate($game->id, $resume->nextDrawAt),
        );
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([3]));

        CarbonImmutable::setTestNow($resume->nextDrawAt);
        try {
            $this->runJob($nextTick);
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);
    }

    public function test_transient_failure_is_logged_rethrown_and_does_not_pause(): void
    {
        Log::spy();
        $game = $this->makeGame();
        $tick = $this->tick($game);
        $this->app->instance(DrawNumberStrategy::class, new class implements DrawNumberStrategy
        {
            public function generate(int $minimum, int $maximum): int
            {
                throw new RuntimeException('temporary draw source failure');
            }

            public function name(): string
            {
                return 'temporary_failure';
            }
        });

        try {
            $this->runJob($tick);
            $this->fail('Expected transient RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('temporary draw source failure', $exception->getMessage());
        }

        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);
        $this->assertNotNull($game->next_draw_at);
        $this->assertSame(
            0,
            GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::GameAutoPaused)
                ->count(),
        );
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'engine.scheduled_draw.transient_failure'
                && $context['failure_type'] === 'transient'
                && $context['game_id'] === $game->id);
    }

    public function test_missing_game_is_an_expected_failure_and_does_not_throw(): void
    {
        Log::spy();
        $tick = new EngineTick(
            gameId: '01900000-0000-7000-8000-000000000001',
            scheduledAt: CarbonImmutable::now(),
            commandId: new DrawCommandId('01900000-0000-7000-8000-000000000002'),
        );

        $this->runJob($tick);

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'engine.scheduled_draw.expected_failure'
                && $context['failure_type'] === 'expected')
            ->once();
    }

    public function test_integrity_failure_does_not_pause_when_calendar_moved_to_another_tick(): void
    {
        $game = $this->makeGame();
        $failedTick = $this->tick($game);
        $game->next_draw_at = $failedTick->scheduledAt->addSeconds($game->draw_interval_seconds);
        $game->saveQuietly();

        $outcome = app(AutoPauseGameAfterIntegrityFailureAction::class)
            ->execute(
                $failedTick,
                GameParticipationIntegrityViolation::withContext('corrupt', ['game_id' => $game->id]),
                'game_participation_integrity_violation',
            );

        $this->assertSame(
            AutoPauseGameOutcome::NotApplicable,
            $outcome,
        );
        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);
        $this->assertSame(
            0,
            GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::GameAutoPaused)
                ->count(),
        );
    }

    private function makeGame(): Game
    {
        $scheduledAt = CarbonImmutable::now()->startOfSecond()->subSeconds(5);
        $game = Game::create([
            'slug' => 'recovery-'.fake()->unique()->lexify('?????'),
            'name' => 'Recovery',
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

    private function runJob(EngineTick $tick): void
    {
        app()->call([new ExecuteScheduledGameDrawJob($tick), 'handle']);
    }
}
