<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameCancelled;
use App\Modules\RepeatNumberBingo\Domain\Events\GamePublished;
use App\Modules\RepeatNumberBingo\Domain\Events\GameSalesClosed;
use App\Modules\RepeatNumberBingo\Domain\Events\GameSalesOpened;
use App\Modules\RepeatNumberBingo\Domain\Events\GameScheduledStartSet;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class GameLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGame(GameStatus $status = GameStatus::Draft, array $overrides = []): Game
    {
        return Game::create(array_replace([
            'slug' => 'rifa-x',
            'name' => 'Rifa X',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => $status,
        ], $overrides));
    }

    public function test_publish_transitions_draft_to_published_and_audits_event(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        Event::fake([GamePublished::class]);
        $game = $this->makeGame();

        $this->postJson("/api/v1/admin/games/{$game->id}/publish")->assertOk();

        $this->assertSame(GameStatus::Published, $game->refresh()->status);
        $this->assertTrue(
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::GamePublished)->exists()
        );
        Event::assertDispatched(GamePublished::class);
    }

    public function test_open_sales_then_close_sales_pipeline(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        Event::fake([GameSalesOpened::class, GameSalesClosed::class]);
        $game = $this->makeGame();

        $this->postJson("/api/v1/admin/games/{$game->id}/publish")->assertOk();
        $this->postJson("/api/v1/admin/games/{$game->id}/open-sales")->assertOk();
        $this->postJson("/api/v1/admin/games/{$game->id}/close-sales")->assertOk();

        $this->assertSame(GameStatus::SalesClosed, $game->refresh()->status);
        $this->assertNotNull($game->sales_opens_at);
        $this->assertNotNull($game->sales_closes_at);

        Event::assertDispatched(GameSalesOpened::class);
        Event::assertDispatched(GameSalesClosed::class);
    }

    public function test_setting_scheduled_start_does_not_change_state(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        Event::fake([GameScheduledStartSet::class]);
        $game = $this->makeGame(GameStatus::Published);

        $start = now()->addDays(2)->toIso8601String();
        $response = $this->postJson("/api/v1/admin/games/{$game->id}/schedule", [
            'scheduled_start_at' => $start,
        ])->assertOk();

        $fresh = $game->refresh();
        $this->assertSame(GameStatus::Published, $fresh->status, 'Status must not change.');
        $this->assertNotNull($fresh->scheduled_start_at);

        $this->assertTrue(
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::ScheduledStartSet)->exists()
        );
        Event::assertDispatched(GameScheduledStartSet::class);

        $this->assertSame('published', $response->json('data.status'));
    }

    public function test_can_set_scheduled_start_while_sales_open(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $game = $this->makeGame(GameStatus::SalesOpen);

        $this->postJson("/api/v1/admin/games/{$game->id}/schedule", [
            'scheduled_start_at' => now()->addDay()->toIso8601String(),
        ])->assertOk();

        $this->assertSame(GameStatus::SalesOpen, $game->refresh()->status);
    }

    public function test_rejects_scheduled_start_before_sales_closes_at(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $closesAt = now()->addDays(5);
        $game = $this->makeGame(GameStatus::SalesOpen, ['sales_closes_at' => $closesAt]);

        $this->postJson("/api/v1/admin/games/{$game->id}/schedule", [
            'scheduled_start_at' => $closesAt->copy()->subHour()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_game_configuration');
    }

    public function test_rejects_scheduled_start_in_a_running_game(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $game = $this->makeGame(GameStatus::Running);

        $this->postJson("/api/v1/admin/games/{$game->id}/schedule", [
            'scheduled_start_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_game_configuration');
    }

    public function test_rejects_past_scheduled_start(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $game = $this->makeGame(GameStatus::Published);

        // Validation at HTTP layer (after:now) → 422 with validation errors.
        $this->postJson("/api/v1/admin/games/{$game->id}/schedule", [
            'scheduled_start_at' => now()->subMinute()->toIso8601String(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_start_at');
    }

    public function test_cancel_writes_audit_with_reason(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        Event::fake([GameCancelled::class]);
        $game = $this->makeGame();

        $this->postJson("/api/v1/admin/games/{$game->id}/cancel", ['reason' => 'oops'])->assertOk();

        $this->assertSame(GameStatus::Cancelled, $game->refresh()->status);

        $audit = GameEvent::query()
            ->where('game_id', $game->id)
            ->where('type', GameEventType::GameCancelled)
            ->firstOrFail();

        $this->assertSame(['reason' => 'oops'], $audit->payload);
        Event::assertDispatched(GameCancelled::class);
    }

    public function test_invalid_transition_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $game = $this->makeGame();

        // Cannot open sales while still in draft (must publish first).
        $this->postJson("/api/v1/admin/games/{$game->id}/open-sales")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_game_transition');

        $this->assertSame(GameStatus::Draft, $game->refresh()->status);
    }
}
