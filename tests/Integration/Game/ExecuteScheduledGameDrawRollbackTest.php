<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Modules\RepeatNumberBingo\Application\Actions\ExecuteScheduledGameDrawAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\ExecuteScheduledGameDrawOutcome;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameNumberDrawn;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameParticipationIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineTickCommandIdGenerator;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

final class ExecuteScheduledGameDrawRollbackTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeContext(int $drawnNumber = 3): array
    {
        $scheduledAt = CarbonImmutable::now()->startOfSecond()->subSeconds(5);
        $game = Game::create([
            'slug' => 'sr-'.fake()->unique()->lexify('?????'),
            'name' => 'Scheduled rollback',
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

        $tick = new EngineTick(
            gameId: $game->id,
            scheduledAt: $scheduledAt,
            commandId: app(EngineTickCommandIdGenerator::class)->generate($game->id, $scheduledAt),
        );

        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([$drawnNumber]));

        return [$game, $tick, app(ExecuteScheduledGameDrawAction::class)];
    }

    public function test_calendar_failure_rolls_back_draw_and_command(): void
    {
        [$game, $tick, $action] = $this->makeContext();

        Game::updating(function (Game $updating): void {
            if ($updating->isDirty('last_consumed_tick_at')) {
                throw new RuntimeException('simulated calendar persistence failure');
            }
        });

        try {
            $action->execute($tick);
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('calendar', $exception->getMessage());
        } finally {
            Game::flushEventListeners();
            Game::boot();
        }

        $this->assertSame(0, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame(0, DrawCommand::query()->where('game_id', $game->id)->count());
        $this->assertSame(0, GameNumberCounter::query()->where('game_id', $game->id)->count());
        $game->refresh();
        $this->assertNull($game->last_consumed_tick_at);
        $this->assertTrue($game->next_draw_at->toImmutable()->equalTo($tick->scheduledAt));
    }

    public function test_draw_failure_does_not_advance_calendar(): void
    {
        [$game, $tick, $action] = $this->makeContext();
        $gameNumber = GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', 3)
            ->firstOrFail();
        $gameNumber->status = GameNumberStatus::Sold;
        $gameNumber->save();

        try {
            $action->execute($tick);
            $this->fail('Expected GameParticipationIntegrityViolation.');
        } catch (GameParticipationIntegrityViolation) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, GameDraw::query()->where('game_id', $game->id)->count());
        $game->refresh();
        $this->assertNull($game->last_consumed_tick_at);
        $this->assertTrue($game->next_draw_at->toImmutable()->equalTo($tick->scheduledAt));
    }

    public function test_listener_failure_does_not_revert_draw_or_calendar(): void
    {
        [$game, $tick, $action] = $this->makeContext();

        Event::listen(GameNumberDrawn::class, function (): void {
            throw new RuntimeException('listener failed');
        });

        $result = $action->execute($tick);

        $this->assertSame(ExecuteScheduledGameDrawOutcome::Executed, $result->outcome);
        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $game->refresh();
        $this->assertTrue($game->last_consumed_tick_at->toImmutable()->equalTo($tick->scheduledAt));
        $this->assertTrue($game->next_draw_at->toImmutable()->equalTo($tick->scheduledAt->addSeconds(30)));
    }

    public function test_skipped_ticks_audit_failure_rolls_back_draw_and_calendar(): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);

        try {
            [$game, $tick, $action] = $this->makeContext();
            $game->next_draw_at = $now->subMinutes(2);
            $game->saveQuietly();
            $tick = new EngineTick(
                gameId: $game->id,
                scheduledAt: $game->next_draw_at->toImmutable(),
                commandId: app(EngineTickCommandIdGenerator::class)->generate(
                    $game->id,
                    $game->next_draw_at->toImmutable(),
                ),
            );

            GameEvent::creating(function (GameEvent $event): void {
                if ($event->type === GameEventType::EngineTicksSkipped) {
                    throw new RuntimeException('simulated skip audit failure');
                }
            });

            try {
                $action->execute($tick);
                $this->fail('Expected RuntimeException.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('skip audit', $exception->getMessage());
            } finally {
                GameEvent::flushEventListeners();
                GameEvent::boot();
            }

            $this->assertSame(0, GameDraw::query()->where('game_id', $game->id)->count());
            $game->refresh();
            $this->assertNull($game->last_consumed_tick_at);
            $this->assertTrue($game->next_draw_at->toImmutable()->equalTo($tick->scheduledAt));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
