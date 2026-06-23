<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

/**
 * Block 4.1 — engine schedule invariants:
 *   - StartGameAction initializes next_draw_at when auto_draw_enabled = true.
 *   - StartGameAction leaves next_draw_at null when auto_draw_enabled = false.
 *   - StartGameAction rejects an out-of-range draw_interval_seconds.
 *   - Completing a game (via draw winner) clears next_draw_at.
 *   - Manual draw endpoint is blocked when auto_draw_enabled = true.
 *   - Manual draw endpoint proceeds when auto_draw_enabled = false.
 */
final class EngineScheduleTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Create a game in SalesClosed state with one sold number and a confirmed
     * entry — the minimum Commerce readiness the start endpoint requires.
     */
    private function makeReadyGame(bool $autoDrawEnabled, int $intervalSeconds = 30): Game
    {
        $game = Game::create([
            'slug' => 'es-'.fake()->unique()->lexify('?????'),
            'name' => 'ES', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => $intervalSeconds,
            'auto_draw_enabled' => $autoDrawEnabled,
            'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create(['game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available]);
        }

        $gn = GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', 1)
            ->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();

        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return $game;
    }

    /**
     * Create a game already in Running state without going through StartGameAction.
     * Useful for testing Draw-level invariants independently of Start.
     */
    private function makeRunningGame(bool $autoDrawEnabled, int $hitsRequired = 2): array
    {
        $game = Game::create([
            'slug' => 'es-'.fake()->unique()->lexify('?????'),
            'name' => 'ES', 'number_min' => 1, 'number_max' => 5,
            'hits_required' => $hitsRequired,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => $autoDrawEnabled,
            'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create(['game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available]);
        }

        $gn = GameNumber::query()
            ->where('game_id', $game->id)
            ->where('number', 1)
            ->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();

        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $admin = User::factory()->admin()->create();

        return [$game, $admin];
    }

    // -------------------------------------------------------------------------
    // StartGameAction — schedule initialization
    // -------------------------------------------------------------------------

    public function test_start_sets_next_draw_at_when_auto_draw_enabled(): void
    {
        $game = $this->makeReadyGame(autoDrawEnabled: true, intervalSeconds: 30);
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $before = CarbonImmutable::now();
        $this->postJson("/api/v1/admin/games/{$game->id}/start")->assertOk();
        $after = CarbonImmutable::now();

        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);
        $this->assertNotNull($game->next_draw_at);

        // next_draw_at must equal started_at + draw_interval_seconds (epoch-level comparison).
        $this->assertEqualsWithDelta(
            $game->started_at->timestamp + $game->draw_interval_seconds,
            $game->next_draw_at->timestamp,
            1,
            'next_draw_at must be started_at + draw_interval_seconds'
        );
    }

    public function test_start_leaves_next_draw_at_null_when_auto_draw_disabled(): void
    {
        $game = $this->makeReadyGame(autoDrawEnabled: false);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/games/{$game->id}/start")->assertOk();

        $game->refresh();
        $this->assertNull($game->next_draw_at);
    }

    public function test_start_fails_when_interval_is_below_configured_minimum(): void
    {
        // Use a valid DB interval (30) but override config min to be higher.
        Config::set('engine.draw_interval_min_seconds', 60);

        $game = $this->makeReadyGame(autoDrawEnabled: true, intervalSeconds: 30);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/games/{$game->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_game_engine_configuration');

        // Game must NOT have been transitioned.
        $game->refresh();
        $this->assertSame(GameStatus::SalesClosed, $game->status);
        $this->assertNull($game->next_draw_at);
    }

    public function test_start_fails_when_interval_is_above_configured_maximum(): void
    {
        Config::set('engine.draw_interval_max_seconds', 20);

        $game = $this->makeReadyGame(autoDrawEnabled: true, intervalSeconds: 30);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/v1/admin/games/{$game->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_game_engine_configuration');
    }

    // -------------------------------------------------------------------------
    // DrawGameNumberAction — auto-draw guard under lock
    // -------------------------------------------------------------------------

    public function test_manual_draw_rejected_when_auto_draw_enabled_and_running(): void
    {
        [$game, $admin] = $this->makeRunningGame(autoDrawEnabled: true);
        Sanctum::actingAs($admin);

        $this->postJson(
            "/api/v1/admin/games/{$game->id}/draws",
            [],
            ['X-Draw-Command-Id' => (string) Str::uuid7()]
        )
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_engine_automation_active');
    }

    public function test_manual_draw_allowed_when_auto_draw_disabled_and_running(): void
    {
        [$game, $admin] = $this->makeRunningGame(autoDrawEnabled: false);
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([3]));
        Sanctum::actingAs($admin);

        $this->postJson(
            "/api/v1/admin/games/{$game->id}/draws",
            [],
            ['X-Draw-Command-Id' => (string) Str::uuid7()]
        )->assertStatus(201);
    }

    public function test_replay_succeeds_regardless_of_auto_draw_enabled(): void
    {
        // Replays (same command_id) must bypass the automation guard.
        [$game, $admin] = $this->makeRunningGame(autoDrawEnabled: false);
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([3, 3]));
        Sanctum::actingAs($admin);

        $commandId = (string) Str::uuid7();

        // First call — fresh draw.
        $this->postJson(
            "/api/v1/admin/games/{$game->id}/draws",
            [],
            ['X-Draw-Command-Id' => $commandId]
        )->assertStatus(201);

        // Enable automation after the first draw, then replay the same command.
        $game->auto_draw_enabled = true;
        $game->saveQuietly();

        // Replay must succeed (200 — the DrawCommand already exists).
        $this->postJson(
            "/api/v1/admin/games/{$game->id}/draws",
            [],
            ['X-Draw-Command-Id' => $commandId]
        )->assertOk();
    }

    // -------------------------------------------------------------------------
    // DrawGameNumberAction — next_draw_at cleared on completion
    // -------------------------------------------------------------------------

    public function test_completing_a_game_clears_next_draw_at(): void
    {
        // Set up a running game with auto_draw_enabled=false (manual draws allowed),
        // hits_required=2, and next_draw_at pre-set to simulate a previously
        // scheduled tick.
        [$game, $admin] = $this->makeRunningGame(autoDrawEnabled: false, hitsRequired: 2);

        $game->next_draw_at = now()->addSeconds(30);
        $game->saveQuietly();

        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1, 1]));
        Sanctum::actingAs($admin);

        $this->postJson(
            "/api/v1/admin/games/{$game->id}/draws",
            [],
            ['X-Draw-Command-Id' => (string) Str::uuid7()]
        )->assertStatus(201);

        $this->postJson(
            "/api/v1/admin/games/{$game->id}/draws",
            [],
            ['X-Draw-Command-Id' => (string) Str::uuid7()]
        )->assertStatus(201);

        $game->refresh();
        $this->assertSame(GameStatus::Completed, $game->status);
        $this->assertNull($game->next_draw_at, 'next_draw_at must be null after game completion.');
        $this->assertNotNull($game->completed_at);
    }

    // -------------------------------------------------------------------------
    // Schema — new nullable columns
    // -------------------------------------------------------------------------

    public function test_new_engine_columns_are_nullable_by_default(): void
    {
        $game = Game::create([
            'slug' => 'es-'.fake()->unique()->lexify('?????'),
            'name' => 'ES', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Draft,
            'scheduled_start_at' => now()->addHour(),
        ]);

        $game->refresh();
        $this->assertNull($game->next_draw_at);
        $this->assertNull($game->last_consumed_tick_at);
        $this->assertNull($game->paused_at);
    }
}
