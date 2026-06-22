<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminEngineFilterValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function game(): Game
    {
        $g = Game::create([
            'slug' => 'fv-'.fake()->unique()->lexify('?????'),
            'name' => 'FV', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create(['game_id' => $g->id, 'number' => $i, 'status' => GameNumberStatus::Available]);
        }

        return $g;
    }

    public function test_draws_sequence_inverted_range_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $g = $this->game();

        $this->getJson("/api/v1/admin/games/{$g->id}/draws?sequence_from=5&sequence_to=1")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sequence_to']);
    }

    public function test_draws_drawn_at_inverted_range_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $g = $this->game();

        $this->getJson("/api/v1/admin/games/{$g->id}/draws?drawn_from=2026-12-31&drawn_to=2026-01-01")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['drawn_to']);
    }

    public function test_draws_per_page_above_max_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $g = $this->game();

        $this->getJson("/api/v1/admin/games/{$g->id}/draws?per_page=999")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_counters_number_inverted_range_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $g = $this->game();

        $this->getJson("/api/v1/admin/games/{$g->id}/counters?number_from=5&number_to=1")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['number_to']);
    }

    public function test_counters_hits_inverted_range_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $g = $this->game();

        $this->getJson("/api/v1/admin/games/{$g->id}/counters?min_hits=10&max_hits=3")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_hits']);
    }

    public function test_counters_per_page_zero_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $g = $this->game();

        $this->getJson("/api/v1/admin/games/{$g->id}/counters?per_page=0")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_per_page_100_is_allowed_on_both_listings(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $g = $this->game();

        $this->getJson("/api/v1/admin/games/{$g->id}/draws?per_page=100")->assertOk();
        $this->getJson("/api/v1/admin/games/{$g->id}/counters?per_page=100")->assertOk();
    }

    public function test_per_page_101_rejected_on_both_listings(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $g = $this->game();

        $this->getJson("/api/v1/admin/games/{$g->id}/draws?per_page=101")
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);
        $this->getJson("/api/v1/admin/games/{$g->id}/counters?per_page=101")
            ->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }
}
