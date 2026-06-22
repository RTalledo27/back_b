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
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

/**
 * Phase 3.9 — single response envelope shape.
 *
 *   - single resources → { "data": {...} }    (no double-wrapping)
 *   - paginated lists  → { "data": [...], "links": {...}, "meta": {...} }
 *   - all dates serialised as ISO 8601.
 */
final class AdminEngineResponseShapeTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, User}
     */
    private function makeReadyGameAndAdmin(int $hitsRequired = 2): array
    {
        $game = Game::create([
            'slug' => 'sh-'.fake()->unique()->lexify('?????'),
            'name' => 'SH', 'number_min' => 1, 'number_max' => 5, 'hits_required' => $hitsRequired,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create(['game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available]);
        }
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();
        GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);

        return [$game, User::factory()->admin()->create()];
    }

    private function isIso8601(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/', $value);
    }

    public function test_start_response_has_single_data_envelope_with_iso_dates(): void
    {
        [$game, $admin] = $this->makeReadyGameAndAdmin();
        Sanctum::actingAs($admin);

        $body = $this->postJson("/api/v1/admin/games/{$game->id}/start")->assertOk()->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('data', $body['data'], 'No double-wrapping.');
        $this->assertTrue($this->isIso8601($body['data']['started_at']));
        $this->assertTrue($this->isIso8601($body['data']['scheduled_start_at']));
    }

    public function test_draw_response_has_single_data_envelope_with_iso_dates(): void
    {
        [$game, $admin] = $this->makeReadyGameAndAdmin();
        $game->status = GameStatus::Running;
        $game->started_at = now()->subSecond();
        $game->saveQuietly();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([3]));
        Sanctum::actingAs($admin);

        $body = $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])
            ->assertStatus(201)
            ->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('data', $body['data']);
        $this->assertTrue($this->isIso8601($body['data']['drawn_at']));
    }

    public function test_rebuild_response_has_single_data_envelope_with_iso_dates(): void
    {
        [$game, $admin] = $this->makeReadyGameAndAdmin();
        $game->status = GameStatus::Running;
        $game->started_at = now()->subSecond();
        $game->saveQuietly();
        Sanctum::actingAs($admin);

        $body = $this->postJson("/api/v1/admin/games/{$game->id}/counters/rebuild")
            ->assertOk()
            ->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('data', $body['data']);
        $this->assertTrue($this->isIso8601($body['data']['rebuilt_at']));
    }

    public function test_winner_response_has_single_data_envelope_with_iso_dates(): void
    {
        [$game, $admin] = $this->makeReadyGameAndAdmin(hitsRequired: 2);
        $game->status = GameStatus::Running;
        $game->started_at = now()->subSecond();
        $game->saveQuietly();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1, 1]));
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])->assertStatus(201);
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])->assertStatus(201);

        $body = $this->getJson("/api/v1/admin/games/{$game->id}/winner")->assertOk()->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('data', $body['data']);
        $this->assertTrue($this->isIso8601($body['data']['won_at']));
    }

    public function test_draws_listing_has_data_links_meta(): void
    {
        [$game, $admin] = $this->makeReadyGameAndAdmin();
        $game->status = GameStatus::Running;
        $game->started_at = now()->subSecond();
        $game->saveQuietly();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1, 2]));
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])->assertStatus(201);
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])->assertStatus(201);

        $body = $this->getJson("/api/v1/admin/games/{$game->id}/draws")->assertOk()->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('links', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertIsArray($body['data']);
        // Date format on a draw row.
        $this->assertTrue($this->isIso8601($body['data'][0]['drawn_at']));
    }

    public function test_counters_listing_has_data_links_meta(): void
    {
        [$game, $admin] = $this->makeReadyGameAndAdmin();
        $game->status = GameStatus::Running;
        $game->started_at = now()->subSecond();
        $game->saveQuietly();
        Sanctum::actingAs($admin);

        $body = $this->getJson("/api/v1/admin/games/{$game->id}/counters")->assertOk()->json();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('links', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertIsArray($body['data']);
    }
}
