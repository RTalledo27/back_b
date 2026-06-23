<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ExecuteScheduledGameDrawAction;
use App\Modules\RepeatNumberBingo\Application\Actions\PauseGameAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ResumeGameAction;
use App\Modules\RepeatNumberBingo\Application\Actions\StartGameAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\Contracts\PublicGameUpdatesPublisher;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Application\DTOs\PauseGameData;
use App\Modules\RepeatNumberBingo\Application\DTOs\PublicGameUpdateReason;
use App\Modules\RepeatNumberBingo\Application\DTOs\ResumeGameData;
use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameData;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameParticipationIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineTickCommandIdGenerator;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameActionActor;
use App\Modules\RepeatNumberBingo\Infrastructure\Broadcasting\Events\PublicGameUpdated;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

final class PublicGameBroadcastingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_start_broadcasts_one_public_update_after_commit(): void
    {
        $game = $this->createReadyGame();
        $admin = User::factory()->admin()->create();
        Event::fake([PublicGameUpdated::class]);

        app(StartGameAction::class)->execute(new StartGameData($game->id, $admin->id));

        Event::assertDispatched(PublicGameUpdated::class, function (PublicGameUpdated $event) use ($game): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()->name === "games.{$game->slug}"
                && $payload['reason'] === 'started'
                && $payload['status'] === 'running'
                && $payload['next_draw_at'] !== null;
        });
        Event::assertDispatched(PublicGameUpdated::class, 1);
    }

    public function test_manual_draw_broadcasts_exactly_once(): void
    {
        [$game, $admin] = $this->createRunningGame(autoDraw: false);
        $this->useDrawSequence([4]);
        Event::fake([PublicGameUpdated::class]);

        app(DrawGameNumberAction::class)->execute(new DrawGameNumberData(
            $game->id,
            $this->commandId(),
            $admin->id,
        ));

        Event::assertDispatched(PublicGameUpdated::class, fn (PublicGameUpdated $event): bool => (
            $event->broadcastWith()['reason'] === 'number_drawn'
            && $event->broadcastWith()['latest_draw']['number'] === 4
        ));
        Event::assertDispatched(PublicGameUpdated::class, 1);
    }

    public function test_automatic_draw_broadcasts_once_with_advanced_calendar(): void
    {
        [$game] = $this->createRunningGame(autoDraw: true);
        $this->useDrawSequence([3]);
        $tick = $this->tick($game);
        Event::fake([PublicGameUpdated::class]);

        app(ExecuteScheduledGameDrawAction::class)->execute($tick);

        Event::assertDispatched(PublicGameUpdated::class, function (PublicGameUpdated $event) use ($tick): bool {
            $payload = $event->broadcastWith();

            return $payload['reason'] === 'number_drawn'
                && $payload['latest_draw']['number'] === 3
                && $payload['next_draw_at'] === $tick->scheduledAt->addSeconds(30)->toIso8601String();
        });
        Event::assertDispatched(PublicGameUpdated::class, 1);
    }

    public function test_draw_replay_does_not_broadcast_again(): void
    {
        [$game, $admin] = $this->createRunningGame(autoDraw: false);
        $this->useDrawSequence([2]);
        $commandId = $this->commandId();
        $action = app(DrawGameNumberAction::class);

        $action->execute(new DrawGameNumberData($game->id, $commandId, $admin->id));
        Event::fake([PublicGameUpdated::class]);

        $action->execute(new DrawGameNumberData($game->id, $commandId, $admin->id));

        Event::assertNotDispatched(PublicGameUpdated::class);
    }

    public function test_draw_rollback_does_not_broadcast(): void
    {
        [$game, $admin] = $this->createRunningGame(autoDraw: false);
        $number = GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', 5)
            ->firstOrFail();
        $number->status = GameNumberStatus::Sold;
        $number->save();
        $this->useDrawSequence([5]);
        Event::fake([PublicGameUpdated::class]);

        try {
            app(DrawGameNumberAction::class)->execute(new DrawGameNumberData(
                $game->id,
                $this->commandId(),
                $admin->id,
            ));
            $this->fail('Expected GameParticipationIntegrityViolation.');
        } catch (GameParticipationIntegrityViolation) {
            $this->addToAssertionCount(1);
        }

        Event::assertNotDispatched(PublicGameUpdated::class);
        $this->assertSame(0, GameDraw::query()->where('game_id', $game->id)->count());
    }

    public function test_pause_and_resume_each_broadcast_once(): void
    {
        [$game, $admin] = $this->createRunningGame(autoDraw: true);
        Event::fake([PublicGameUpdated::class]);

        app(PauseGameAction::class)->execute(new PauseGameData(
            $game->id,
            GameActionActor::admin($admin->id),
        ));

        Event::assertDispatched(PublicGameUpdated::class, fn (PublicGameUpdated $event): bool => (
            $event->broadcastWith()['reason'] === 'paused'
            && $event->broadcastWith()['status'] === 'paused'
            && $event->broadcastWith()['next_draw_at'] === null
        ));
        Event::assertDispatched(PublicGameUpdated::class, 1);

        Event::fake([PublicGameUpdated::class]);

        app(ResumeGameAction::class)->execute(new ResumeGameData(
            $game->id,
            GameActionActor::admin($admin->id),
        ));

        Event::assertDispatched(PublicGameUpdated::class, fn (PublicGameUpdated $event): bool => (
            $event->broadcastWith()['reason'] === 'resumed'
            && $event->broadcastWith()['status'] === 'running'
            && $event->broadcastWith()['next_draw_at'] !== null
        ));
        Event::assertDispatched(PublicGameUpdated::class, 1);
    }

    public function test_winning_draw_broadcasts_one_final_snapshot_without_duplicate_completion_updates(): void
    {
        [$game, $admin] = $this->createRunningGame(autoDraw: false, hitsRequired: 2);
        $number = GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', 1)
            ->firstOrFail();
        $number->status = GameNumberStatus::Sold;
        $number->save();
        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        $this->useDrawSequence([1, 1]);
        $action = app(DrawGameNumberAction::class);
        $action->execute(new DrawGameNumberData(
            $game->id,
            $this->commandId(),
            $admin->id,
        ));
        Event::fake([PublicGameUpdated::class]);

        $action->execute(new DrawGameNumberData(
            $game->id,
            $this->commandId(),
            $admin->id,
        ));

        Event::assertDispatched(PublicGameUpdated::class, function (PublicGameUpdated $event): bool {
            $payload = $event->broadcastWith();

            return $payload['reason'] === 'number_drawn'
                && $payload['status'] === 'completed'
                && $payload['next_draw_at'] === null
                && $payload['winner']['number'] === 1
                && $payload['winner']['hits'] === 2;
        });
        Event::assertDispatched(PublicGameUpdated::class, 1);
    }

    public function test_public_event_contract_uses_public_slug_channel_and_contains_no_internal_fields(): void
    {
        [$game, $admin] = $this->createRunningGame(autoDraw: false);
        $game->settings = ['engine_secret' => 'hidden'];
        $game->saveQuietly();
        $this->useDrawSequence([2]);
        Event::fake([PublicGameUpdated::class]);

        app(DrawGameNumberAction::class)->execute(new DrawGameNumberData(
            $game->id,
            $this->commandId(),
            $admin->id,
        ));

        Event::assertDispatched(PublicGameUpdated::class, function (PublicGameUpdated $event) use ($game): bool {
            $payload = $event->broadcastWith();
            $json = json_encode($payload, JSON_THROW_ON_ERROR);

            $this->assertSame("games.{$game->slug}", $event->broadcastOn()->name);
            $this->assertSame('public.game.updated.v1', $event->broadcastAs());
            $this->assertSame([
                'schema_version',
                'reason',
                'game_slug',
                'status',
                'occurred_at',
                'latest_draw',
                'next_draw_at',
                'winner',
            ], array_keys($payload));
            $this->assertStringEndsWith('+00:00', $payload['occurred_at']);

            foreach ([
                'game_id',
                'draw_id',
                'command_id',
                'user_id',
                'entry_id',
                'audit',
                'metadata',
                'strategy',
                'settings',
                'engine_secret',
                'error',
            ] as $internalField) {
                $this->assertStringNotContainsString($internalField, $json);
            }

            return true;
        });
    }

    public function test_broadcast_publisher_failure_is_reported_and_does_not_revert_draw(): void
    {
        [$game, $admin] = $this->createRunningGame(autoDraw: false);
        $this->useDrawSequence([3]);
        Exceptions::fake();
        $this->app->instance(PublicGameUpdatesPublisher::class, new class implements PublicGameUpdatesPublisher
        {
            public function publish(
                string $gameId,
                PublicGameUpdateReason $reason,
                CarbonImmutable $occurredAt,
            ): void {
                throw new RuntimeException('broadcast transport failed');
            }
        });

        app(DrawGameNumberAction::class)->execute(new DrawGameNumberData(
            $game->id,
            $this->commandId(),
            $admin->id,
        ));

        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame(GameStatus::Running, $game->refresh()->status);
    }

    private function createReadyGame(): Game
    {
        $game = Game::create([
            'slug' => 'start-'.fake()->unique()->lexify('?????'),
            'name' => 'Start',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => 2,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
        ]);
        $number = GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => GameNumberStatus::Sold,
        ]);
        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return $game;
    }

    /**
     * @return array{Game, User}
     */
    private function createRunningGame(bool $autoDraw, int $hitsRequired = 5): array
    {
        $nextDrawAt = CarbonImmutable::now()->startOfSecond()->subSeconds(5);
        $game = Game::create([
            'slug' => 'live-'.fake()->unique()->lexify('?????'),
            'name' => 'Live',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => $hitsRequired,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => $autoDraw,
            'status' => GameStatus::Running,
            'scheduled_start_at' => $nextDrawAt->subHour(),
            'started_at' => $nextDrawAt->subMinutes(10),
            'next_draw_at' => $autoDraw ? $nextDrawAt : null,
        ]);

        for ($number = 1; $number <= 5; $number++) {
            GameNumber::create([
                'game_id' => $game->id,
                'number' => $number,
                'status' => GameNumberStatus::Available,
            ]);
        }

        return [$game, User::factory()->admin()->create()];
    }

    private function useDrawSequence(array $numbers): void
    {
        $this->app->instance(
            DrawNumberStrategy::class,
            new DeterministicDrawNumberStrategy($numbers),
        );
    }

    private function commandId(): DrawCommandId
    {
        return new DrawCommandId((string) Str::uuid7());
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
}
