<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameEngineConfigurationLocked;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Block 4.1 — model-level engine-config freeze.
 *
 * The Game::updating hook rejects real changes to auto_draw_enabled and
 * draw_interval_seconds while the game is in Running, Paused, Resolving, or
 * Completed. Assigning the same stored value must always be allowed.
 */
final class GameEngineConfigLockTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGame(GameStatus $status, bool $autoDrawEnabled = false, int $interval = 30): Game
    {
        return Game::create([
            'slug' => 'lk-'.fake()->unique()->lexify('?????'),
            'name' => 'LK',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => 2,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => $interval,
            'auto_draw_enabled' => $autoDrawEnabled,
            'status' => $status,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => in_array($status, [
                GameStatus::Running,
                GameStatus::Paused,
                GameStatus::Resolving,
                GameStatus::Completed,
            ], true) ? now()->subMinute() : null,
            'completed_at' => $status === GameStatus::Completed ? now() : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // auto_draw_enabled — locked statuses
    // -------------------------------------------------------------------------

    public function test_auto_draw_enabled_locked_in_running(): void
    {
        $game = $this->makeGame(GameStatus::Running, autoDrawEnabled: false);

        $this->expectException(GameEngineConfigurationLocked::class);
        $game->auto_draw_enabled = true;
        $game->save();
    }

    public function test_auto_draw_enabled_locked_in_paused(): void
    {
        $game = $this->makeGame(GameStatus::Paused, autoDrawEnabled: false);

        $this->expectException(GameEngineConfigurationLocked::class);
        $game->auto_draw_enabled = true;
        $game->save();
    }

    public function test_auto_draw_enabled_locked_in_resolving(): void
    {
        $game = $this->makeGame(GameStatus::Resolving, autoDrawEnabled: false);

        $this->expectException(GameEngineConfigurationLocked::class);
        $game->auto_draw_enabled = true;
        $game->save();
    }

    public function test_auto_draw_enabled_locked_in_completed(): void
    {
        $game = $this->makeGame(GameStatus::Completed, autoDrawEnabled: false);

        $this->expectException(GameEngineConfigurationLocked::class);
        $game->auto_draw_enabled = true;
        $game->save();
    }

    // -------------------------------------------------------------------------
    // draw_interval_seconds — locked statuses
    // -------------------------------------------------------------------------

    public function test_draw_interval_locked_in_running(): void
    {
        $game = $this->makeGame(GameStatus::Running, interval: 30);

        $this->expectException(GameEngineConfigurationLocked::class);
        $game->draw_interval_seconds = 60;
        $game->save();
    }

    public function test_draw_interval_locked_in_paused(): void
    {
        $game = $this->makeGame(GameStatus::Paused, interval: 30);

        $this->expectException(GameEngineConfigurationLocked::class);
        $game->draw_interval_seconds = 60;
        $game->save();
    }

    public function test_draw_interval_locked_in_resolving(): void
    {
        $game = $this->makeGame(GameStatus::Resolving, interval: 30);

        $this->expectException(GameEngineConfigurationLocked::class);
        $game->draw_interval_seconds = 60;
        $game->save();
    }

    public function test_draw_interval_locked_in_completed(): void
    {
        $game = $this->makeGame(GameStatus::Completed, interval: 30);

        $this->expectException(GameEngineConfigurationLocked::class);
        $game->draw_interval_seconds = 60;
        $game->save();
    }

    // -------------------------------------------------------------------------
    // Same value → no-op, no exception
    // -------------------------------------------------------------------------

    public function test_same_auto_draw_value_is_allowed_in_running(): void
    {
        $game = $this->makeGame(GameStatus::Running, autoDrawEnabled: false);

        $game->auto_draw_enabled = false;
        $game->save();

        $this->assertFalse($game->fresh()->auto_draw_enabled);
    }

    public function test_same_interval_value_is_allowed_in_running(): void
    {
        $game = $this->makeGame(GameStatus::Running, interval: 30);

        $game->draw_interval_seconds = 30;
        $game->save();

        $this->assertSame(30, $game->fresh()->draw_interval_seconds);
    }

    // -------------------------------------------------------------------------
    // Pre-start statuses allow changes
    // -------------------------------------------------------------------------

    public function test_auto_draw_enabled_can_change_in_draft(): void
    {
        $game = $this->makeGame(GameStatus::Draft, autoDrawEnabled: false);

        $game->auto_draw_enabled = true;
        $game->save();

        $this->assertTrue($game->fresh()->auto_draw_enabled);
    }

    public function test_auto_draw_enabled_can_change_in_published(): void
    {
        $game = $this->makeGame(GameStatus::Published, autoDrawEnabled: false);

        $game->auto_draw_enabled = true;
        $game->save();

        $this->assertTrue($game->fresh()->auto_draw_enabled);
    }

    public function test_auto_draw_enabled_can_change_in_sales_open(): void
    {
        $game = $this->makeGame(GameStatus::SalesOpen, autoDrawEnabled: false);

        $game->auto_draw_enabled = true;
        $game->save();

        $this->assertTrue($game->fresh()->auto_draw_enabled);
    }

    public function test_auto_draw_enabled_can_change_in_sales_closed(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, autoDrawEnabled: false);

        $game->auto_draw_enabled = true;
        $game->save();

        $this->assertTrue($game->fresh()->auto_draw_enabled);
    }

    public function test_draw_interval_can_change_in_sales_closed(): void
    {
        $game = $this->makeGame(GameStatus::SalesClosed, interval: 30);

        $game->draw_interval_seconds = 60;
        $game->save();

        $this->assertSame(60, $game->fresh()->draw_interval_seconds);
    }

    // -------------------------------------------------------------------------
    // Simultaneous status change + engine-config change → still blocked
    // (guard uses persisted original status, not the in-memory new status)
    // -------------------------------------------------------------------------

    public function test_running_to_unlocked_status_with_interval_change_is_blocked(): void
    {
        $game = $this->makeGame(GameStatus::Running, interval: 30);

        $this->expectException(GameEngineConfigurationLocked::class);

        // Both status and interval changed in one save — original is Running (locked).
        $game->status = GameStatus::SalesClosed;
        $game->draw_interval_seconds = 60;
        $game->save();
    }

    public function test_paused_to_unlocked_status_with_auto_draw_change_is_blocked(): void
    {
        $game = $this->makeGame(GameStatus::Paused, autoDrawEnabled: false);

        $this->expectException(GameEngineConfigurationLocked::class);

        $game->status = GameStatus::Published;
        $game->auto_draw_enabled = true;
        $game->save();
    }
}
