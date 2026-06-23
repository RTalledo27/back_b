<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\PauseGameAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\PauseGameData;
use App\Modules\RepeatNumberBingo\Application\DTOs\PauseGameOutcome;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GamePaused;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameEngineAutomationInactive;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameActionActor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PauseGameActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeRunningGame(): Game
    {
        return Game::create([
            'slug' => 'pa-'.fake()->unique()->lexify('?????'),
            'name' => 'PA', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
            'next_draw_at' => now()->addSeconds(30),
            'last_consumed_tick_at' => now()->subSeconds(10),
        ]);
    }

    public function test_running_to_paused_clears_next_draw_at_and_sets_paused_at(): void
    {
        $game = $this->makeRunningGame();
        $admin = User::factory()->admin()->create();
        Event::fake([GamePaused::class]);

        $result = app(PauseGameAction::class)->execute(new PauseGameData(
            gameId: $game->id,
            actor: GameActionActor::admin($admin->id),
        ));

        $this->assertSame(PauseGameOutcome::Paused, $result->outcome);

        $game->refresh();
        $this->assertSame(GameStatus::Paused, $game->status);
        $this->assertNotNull($game->paused_at);
        $this->assertNull($game->next_draw_at);
        $this->assertNotNull($game->last_consumed_tick_at, 'last_consumed_tick_at must be preserved');

        Event::assertDispatched(GamePaused::class);
    }

    public function test_pause_writes_one_audit_event(): void
    {
        $game = $this->makeRunningGame();
        $admin = User::factory()->admin()->create();

        app(PauseGameAction::class)->execute(new PauseGameData(
            gameId: $game->id,
            actor: GameActionActor::admin($admin->id),
        ));

        $this->assertSame(
            1,
            GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::GamePaused)
                ->count(),
        );
    }

    public function test_replay_returns_already_paused_without_duplicate_audit_or_event(): void
    {
        $game = $this->makeRunningGame();
        $admin = User::factory()->admin()->create();

        app(PauseGameAction::class)->execute(new PauseGameData(
            gameId: $game->id,
            actor: GameActionActor::admin($admin->id),
        ));

        Event::fake([GamePaused::class]);

        $result = app(PauseGameAction::class)->execute(new PauseGameData(
            gameId: $game->id,
            actor: GameActionActor::admin($admin->id),
        ));

        $this->assertSame(PauseGameOutcome::AlreadyPaused, $result->outcome);
        Event::assertNotDispatched(GamePaused::class);

        $this->assertSame(
            1,
            GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::GamePaused)
                ->count(),
        );
    }

    public function test_pause_on_non_running_status_throws_invalid_transition(): void
    {
        $game = Game::create([
            'slug' => 'pa-'.fake()->unique()->lexify('?????'),
            'name' => 'PA', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
        ]);

        $this->expectException(InvalidGameTransition::class);

        app(PauseGameAction::class)->execute(new PauseGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    public function test_http_endpoint_returns_200_with_resource(): void
    {
        $game = $this->makeRunningGame();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/games/{$game->id}/pause")
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.outcome', 'paused');
    }

    public function test_http_endpoint_replay_returns_already_paused(): void
    {
        $game = $this->makeRunningGame();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/games/{$game->id}/pause")->assertOk();
        $this->postJson("/api/v1/admin/games/{$game->id}/pause")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'already_paused');
    }

    // -------------------------------------------------------------------------
    // Engine-automation guard
    // -------------------------------------------------------------------------

    public function test_pause_rejected_when_auto_draw_enabled_false(): void
    {
        $game = $this->makeRunningGame();
        $game->auto_draw_enabled = false;
        $game->saveQuietly();

        $this->expectException(GameEngineAutomationInactive::class);

        app(PauseGameAction::class)->execute(new PauseGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    public function test_http_pause_rejected_with_422_when_auto_draw_disabled(): void
    {
        $game = $this->makeRunningGame();
        $game->auto_draw_enabled = false;
        $game->saveQuietly();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/games/{$game->id}/pause")
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_engine_automation_inactive');
    }

    // -------------------------------------------------------------------------
    // Integrity checks — corrupted running must not be paused
    // -------------------------------------------------------------------------

    public function test_running_corrupt_started_at_null_throws_integrity(): void
    {
        $game = $this->makeRunningGame();
        $game->started_at = null;
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);

        app(PauseGameAction::class)->execute(new PauseGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    public function test_running_corrupt_completed_at_set_throws_integrity(): void
    {
        $game = $this->makeRunningGame();
        $game->completed_at = now();
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);

        app(PauseGameAction::class)->execute(new PauseGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    public function test_running_corrupt_paused_at_set_throws_integrity(): void
    {
        $game = $this->makeRunningGame();
        $game->paused_at = now();
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);

        app(PauseGameAction::class)->execute(new PauseGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    // -------------------------------------------------------------------------
    // Listener failure does NOT roll back the pause
    // -------------------------------------------------------------------------

    public function test_listener_failure_does_not_revert_pause(): void
    {
        $game = $this->makeRunningGame();
        $admin = User::factory()->admin()->create();

        Event::listen(GamePaused::class, function (): void {
            throw new \RuntimeException('Boom from listener');
        });

        $result = app(PauseGameAction::class)->execute(new PauseGameData(
            gameId: $game->id,
            actor: GameActionActor::admin($admin->id),
        ));

        $this->assertSame(PauseGameOutcome::Paused, $result->outcome);

        $game->refresh();
        $this->assertSame(GameStatus::Paused, $game->status);
        $this->assertNotNull($game->paused_at);
    }

    // -------------------------------------------------------------------------
    // Auth (401 unauth, 403 non-admin)
    // -------------------------------------------------------------------------

    public function test_pause_endpoint_requires_authentication(): void
    {
        $game = $this->makeRunningGame();

        $this->postJson("/api/v1/admin/games/{$game->id}/pause")
            ->assertStatus(401);
    }

    public function test_pause_endpoint_forbidden_for_non_admin(): void
    {
        $game = $this->makeRunningGame();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/admin/games/{$game->id}/pause")
            ->assertStatus(403);
    }
}
