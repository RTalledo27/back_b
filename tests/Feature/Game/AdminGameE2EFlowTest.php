<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * End-to-end admin game flow:
 *   1. Admin logs in via local auth and receives a Sanctum token.
 *   2. Token is used to list games, apply a status filter, and fetch a detail.
 *   3. Verifies the full request chain works without shortcuts (Sanctum::actingAs).
 */
final class AdminGameE2EFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGame(string $slug, GameStatus $status): Game
    {
        return Game::create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 5000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 60,
            'auto_draw_enabled' => false,
            'status' => $status,
        ]);
    }

    public function test_admin_can_login_then_list_all_games(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin-e2e@example.com',
            'password' => 'secret123',
        ]);

        $this->makeGame('e2e-draft', GameStatus::Draft);
        $this->makeGame('e2e-published', GameStatus::Published);
        $this->makeGame('e2e-cancelled', GameStatus::Cancelled);

        // Step 1 — login and obtain a real Sanctum token.
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-e2e@example.com',
            'password' => 'secret123',
        ])->assertOk()->json('data.access_token');

        $this->assertNotEmpty($token);

        // Step 2 — use the token to list all games (admin sees all statuses).
        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/games')
            ->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains('e2e-draft', $slugs);
        $this->assertContains('e2e-published', $slugs);
        $this->assertContains('e2e-cancelled', $slugs);
    }

    public function test_admin_can_filter_by_status_after_login(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin-filter@example.com',
            'password' => 'secret123',
        ]);

        $this->makeGame('filter-open', GameStatus::SalesOpen);
        $this->makeGame('filter-running', GameStatus::Running);
        $this->makeGame('filter-draft', GameStatus::Draft);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-filter@example.com',
            'password' => 'secret123',
        ])->assertOk()->json('data.access_token');

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/games?status=sales_open')
            ->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains('filter-open', $slugs);
        $this->assertNotContains('filter-running', $slugs);
        $this->assertNotContains('filter-draft', $slugs);
    }

    public function test_admin_can_fetch_game_detail_after_login(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin-detail@example.com',
            'password' => 'secret123',
        ]);

        $game = $this->makeGame('e2e-detail-game', GameStatus::SalesOpen);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-detail@example.com',
            'password' => 'secret123',
        ])->assertOk()->json('data.access_token');

        $this->withToken($token)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $game->id)
            ->assertJsonPath('data.slug', 'e2e-detail-game')
            ->assertJsonPath('data.status', 'sales_open')
            ->assertJsonStructure([
                'data' => ['id', 'slug', 'status', 'settings', 'numbers', 'commerce', 'projection'],
            ]);
    }

    public function test_player_token_from_login_cannot_access_admin_games(): void
    {
        User::factory()->create([
            'email' => 'player-e2e@example.com',
            'password' => 'secret123',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'player-e2e@example.com',
            'password' => 'secret123',
        ])->assertOk()->json('data.access_token');

        $this->withToken($token)
            ->getJson('/api/v1/admin/games')
            ->assertForbidden();
    }

    public function test_token_revoked_after_logout_cannot_access_admin_games(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin-logout@example.com',
            'password' => 'secret123',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-logout@example.com',
            'password' => 'secret123',
        ])->assertOk()->json('data.access_token');

        // Confirm access before logout.
        $this->withToken($token)
            ->getJson('/api/v1/admin/games')
            ->assertOk();

        // Logout revokes the token.
        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        // Clear the guard cache so the next request re-checks the DB.
        Auth::forgetGuards();

        // Same token must no longer work.
        $this->withToken($token)
            ->getJson('/api/v1/admin/games')
            ->assertUnauthorized();
    }
}
