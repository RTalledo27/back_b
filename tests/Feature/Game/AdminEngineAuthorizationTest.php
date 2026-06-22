<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 3.8 engine endpoints — authorization matrix.
 */
final class AdminEngineAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGame(): Game
    {
        return Game::create([
            'slug' => 'auth-'.fake()->unique()->lexify('?????'),
            'name' => 'AUTH', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function endpoints(): iterable
    {
        return [
            'start' => ['POST', '/start'],
            'draws_create' => ['POST', '/draws'],
            'rebuild' => ['POST', '/counters/rebuild'],
            'list_draws' => ['GET', '/draws'],
            'list_counters' => ['GET', '/counters'],
            'show_winner' => ['GET', '/winner'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_unauthenticated_returns_401(string $method, string $path): void
    {
        $game = $this->makeGame();
        $this->json($method, "/api/v1/admin/games/{$game->id}{$path}", [], $this->commandIdHeaderIfNeeded($path))->assertStatus(401);
    }

    #[DataProvider('endpoints')]
    public function test_player_returns_403(string $method, string $path): void
    {
        $game = $this->makeGame();
        Sanctum::actingAs(User::factory()->create());
        $this->json($method, "/api/v1/admin/games/{$game->id}{$path}", [], $this->commandIdHeaderIfNeeded($path))->assertStatus(403);
    }

    /**
     * @return array<string, string>
     */
    private function commandIdHeaderIfNeeded(string $path): array
    {
        return $path === '/draws'
            ? ['X-Draw-Command-Id' => (string) Str::uuid7()]
            : [];
    }
}
