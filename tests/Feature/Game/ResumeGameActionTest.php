<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\ResumeGameAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\ResumeGameData;
use App\Modules\RepeatNumberBingo\Application\DTOs\ResumeGameOutcome;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameResumed;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameEngineAutomationInactive;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameEngineConfiguration;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineGridCalculator;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameActionActor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ResumeGameActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makePausedGame(
        int $interval = 30,
        ?CarbonImmutable $startedAt = null,
        ?CarbonImmutable $pausedAt = null,
    ): Game {
        return Game::create([
            'slug' => 're-'.fake()->unique()->lexify('?????'),
            'name' => 'RE', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => $interval,
            'auto_draw_enabled' => true, 'status' => GameStatus::Paused,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => $startedAt ?? now()->subMinutes(2),
            'paused_at' => $pausedAt ?? now()->subMinute(),
            'next_draw_at' => null,
            'last_consumed_tick_at' => now()->subSeconds(45),
        ]);
    }

    public function test_paused_to_running_clears_paused_at_and_sets_aligned_next_draw_at(): void
    {
        // startedAt aligned: T+0, T+30, T+60 ...
        // now around T+95 → first slot strictly after = T+120
        $startedAt = CarbonImmutable::now()->subSeconds(95);
        $game = $this->makePausedGame(interval: 30, startedAt: $startedAt);
        $admin = User::factory()->admin()->create();
        Event::fake([GameResumed::class]);

        $result = app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin($admin->id),
        ));

        $this->assertSame(ResumeGameOutcome::Resumed, $result->outcome);

        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);
        $this->assertNull($game->paused_at);
        $this->assertNotNull($game->next_draw_at);

        // next_draw_at must be strictly after now and aligned on the grid.
        $this->assertGreaterThan(now()->timestamp, $game->next_draw_at->timestamp);
        $diff = $game->next_draw_at->timestamp - $startedAt->timestamp;
        $this->assertSame(0, $diff % 30, 'next_draw_at must be on the grid');

        Event::assertDispatched(GameResumed::class);
    }

    public function test_resume_writes_one_audit_event(): void
    {
        $game = $this->makePausedGame();
        $admin = User::factory()->admin()->create();

        app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin($admin->id),
        ));

        $this->assertSame(
            1,
            GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::GameResumed)
                ->count(),
        );
    }

    public function test_replay_returns_already_running_without_duplicate_audit_or_event(): void
    {
        $game = $this->makePausedGame();
        $admin = User::factory()->admin()->create();

        app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin($admin->id),
        ));

        Event::fake([GameResumed::class]);

        $result = app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin($admin->id),
        ));

        $this->assertSame(ResumeGameOutcome::AlreadyRunning, $result->outcome);
        Event::assertNotDispatched(GameResumed::class);

        $this->assertSame(
            1,
            GameEvent::query()
                ->where('game_id', $game->id)
                ->where('type', GameEventType::GameResumed)
                ->count(),
        );
    }

    public function test_resume_on_non_paused_status_throws_invalid_transition(): void
    {
        $game = Game::create([
            'slug' => 're-'.fake()->unique()->lexify('?????'),
            'name' => 'RE', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
        ]);

        $this->expectException(InvalidGameTransition::class);

        app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    public function test_resume_fails_when_interval_out_of_config(): void
    {
        Config::set('engine.draw_interval_min_seconds', 60);
        $game = $this->makePausedGame(interval: 30);

        $this->expectException(InvalidGameEngineConfiguration::class);

        app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    public function test_resume_fails_when_started_at_is_null(): void
    {
        $game = $this->makePausedGame();
        $game->started_at = null;
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);

        app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    public function test_resume_fails_when_completed_at_is_set(): void
    {
        $game = $this->makePausedGame();
        $game->completed_at = now();
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);

        app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    public function test_http_endpoint_returns_200_with_resource(): void
    {
        $game = $this->makePausedGame();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/games/{$game->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', 'running')
            ->assertJsonPath('data.outcome', 'resumed');
    }

    public function test_http_endpoint_replay_returns_already_running(): void
    {
        $game = $this->makePausedGame();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/games/{$game->id}/resume")->assertOk();
        $this->postJson("/api/v1/admin/games/{$game->id}/resume")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'already_running');
    }

    // -------------------------------------------------------------------------
    // Engine-automation guard
    // -------------------------------------------------------------------------

    public function test_resume_rejected_when_auto_draw_enabled_false(): void
    {
        $game = $this->makePausedGame();
        $game->auto_draw_enabled = false;
        $game->saveQuietly();

        $this->expectException(GameEngineAutomationInactive::class);

        app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    public function test_http_resume_rejected_with_422_when_auto_draw_disabled(): void
    {
        $game = $this->makePausedGame();
        $game->auto_draw_enabled = false;
        $game->saveQuietly();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/games/{$game->id}/resume")
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_engine_automation_inactive');
    }

    // -------------------------------------------------------------------------
    // Calculator is reused — same result as direct EngineGridCalculator call
    // -------------------------------------------------------------------------

    public function test_resume_uses_engine_grid_calculator(): void
    {
        $startedAt = CarbonImmutable::now()->subSeconds(95);
        $game = $this->makePausedGame(interval: 30, startedAt: $startedAt);

        $now = CarbonImmutable::now();
        $expected = (new EngineGridCalculator)->skipToNext($startedAt, 30, $now)->timestamp;

        $result = app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));

        $game->refresh();
        // Allow ±1 second drift between $now snapshot and the action's internal now().
        $this->assertEqualsWithDelta($expected, $game->next_draw_at->timestamp, 30,
            'next_draw_at must lie on the same grid as EngineGridCalculator::skipToNext');
        // Must be strictly future and aligned to the started_at + N*interval grid.
        $this->assertSame(0, ($game->next_draw_at->timestamp - $startedAt->timestamp) % 30);
        $this->assertGreaterThan(now()->timestamp, $game->next_draw_at->timestamp);
        $this->assertSame(
            ResumeGameOutcome::Resumed,
            $result->outcome,
        );
    }

    // -------------------------------------------------------------------------
    // Paused-state integrity checks
    // -------------------------------------------------------------------------

    public function test_paused_with_null_paused_at_throws_integrity(): void
    {
        $game = $this->makePausedGame();
        $game->paused_at = null;
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);

        app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    public function test_paused_with_next_draw_at_set_throws_integrity(): void
    {
        $game = $this->makePausedGame();
        $game->next_draw_at = now()->addSeconds(30);
        $game->saveQuietly();

        $this->expectException(GameLifecycleIntegrityViolation::class);

        app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin(User::factory()->admin()->create()->id),
        ));
    }

    // -------------------------------------------------------------------------
    // Listener failure does NOT roll back the resume
    // -------------------------------------------------------------------------

    public function test_listener_failure_does_not_revert_resume(): void
    {
        $game = $this->makePausedGame();
        $admin = User::factory()->admin()->create();

        Event::listen(GameResumed::class, function (): void {
            throw new \RuntimeException('Boom from listener');
        });

        $result = app(ResumeGameAction::class)->execute(new ResumeGameData(
            gameId: $game->id,
            actor: GameActionActor::admin($admin->id),
        ));

        $this->assertSame(
            ResumeGameOutcome::Resumed,
            $result->outcome,
        );

        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);
        $this->assertNull($game->paused_at);
        $this->assertNotNull($game->next_draw_at);
    }

    // -------------------------------------------------------------------------
    // Auth (401 unauth, 403 non-admin)
    // -------------------------------------------------------------------------

    public function test_resume_endpoint_requires_authentication(): void
    {
        $game = $this->makePausedGame();

        $this->postJson("/api/v1/admin/games/{$game->id}/resume")
            ->assertStatus(401);
    }

    public function test_resume_endpoint_forbidden_for_non_admin(): void
    {
        $game = $this->makePausedGame();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/admin/games/{$game->id}/resume")
            ->assertStatus(403);
    }
}
