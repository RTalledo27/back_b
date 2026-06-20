<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class PublicEndpointsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGame(string $slug, GameStatus $status): Game
    {
        return Game::create([
            'slug' => $slug,
            'name' => "Rifa {$slug}",
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => $status,
        ]);
    }

    public function test_list_excludes_draft_and_cancelled_games(): void
    {
        $this->makeGame('a-draft', GameStatus::Draft);
        $this->makeGame('b-published', GameStatus::Published);
        $this->makeGame('c-cancelled', GameStatus::Cancelled);
        $this->makeGame('d-sales-open', GameStatus::SalesOpen);

        $response = $this->getJson('/api/v1/public/games')->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains('b-published', $slugs);
        $this->assertContains('d-sales-open', $slugs);
        $this->assertNotContains('a-draft', $slugs);
        $this->assertNotContains('c-cancelled', $slugs);
    }

    public function test_public_resource_does_not_leak_internal_admin_fields(): void
    {
        $game = $this->makeGame('public-1', GameStatus::Published);
        $game->settings = ['internal_note' => 'do-not-leak'];
        $game->save();

        $body = $this->getJson("/api/v1/public/games/{$game->slug}")
            ->assertOk()
            ->json();

        $this->assertArrayNotHasKey('settings', $body['data']);
        $this->assertArrayNotHasKey('created_by', $body['data']);
        $this->assertSame($game->slug, $body['data']['slug']);
    }

    public function test_show_returns_404_for_draft_game(): void
    {
        $this->makeGame('hidden', GameStatus::Draft);

        $this->getJson('/api/v1/public/games/hidden')->assertNotFound();
    }

    public function test_show_returns_404_for_cancelled_game(): void
    {
        $this->makeGame('gone', GameStatus::Cancelled);

        $this->getJson('/api/v1/public/games/gone')->assertNotFound();
    }
}
