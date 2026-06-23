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
 * Listing endpoints must be strictly scoped to the {game} route parameter.
 * Draws / counters / winner from a different game must never leak into the
 * response — neither through pagination totals nor through filters.
 */
final class AdminEngineGameIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, User, GameEntry}
     */
    private function makeRunningGameWithSoldNumber(): array
    {
        $game = Game::create([
            'slug' => 'iso-'.fake()->unique()->lexify('?????'),
            'name' => 'ISO', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create(['game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available]);
        }
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);

        return [$game, User::factory()->admin()->create(), $entry];
    }

    private function performWinningRun(Game $game, User $admin): void
    {
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1, 1]));
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])
            ->assertStatus(201);
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])
            ->assertStatus(201);
    }

    public function test_draws_listing_does_not_leak_other_games_draws(): void
    {
        [$gameA, $admin] = $this->makeRunningGameWithSoldNumber();
        [$gameB] = $this->makeRunningGameWithSoldNumber();

        // Two draws for B; zero for A.
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([2, 3]));
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/games/{$gameB->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])->assertStatus(201);
        $this->postJson("/api/v1/admin/games/{$gameB->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])->assertStatus(201);

        $resp = $this->getJson("/api/v1/admin/games/{$gameA->id}/draws")->assertOk();
        $this->assertSame(0, count($resp->json('data')));
        $this->assertSame(0, (int) $resp->json('meta.total'));
    }

    public function test_counters_listing_does_not_leak_other_games_counters(): void
    {
        [$gameA, $admin] = $this->makeRunningGameWithSoldNumber();
        [$gameB] = $this->makeRunningGameWithSoldNumber();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([2]));
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/games/{$gameB->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])->assertStatus(201);

        $resp = $this->getJson("/api/v1/admin/games/{$gameA->id}/counters")->assertOk();
        // Every counter must belong to A.
        foreach ($resp->json('data') as $row) {
            $this->assertContains($row['number'], [1, 2, 3, 4, 5]);
            $this->assertSame(0, $row['hits_count']);  // none of A's numbers was drawn
        }
        $this->assertSame(5, (int) $resp->json('meta.total'));
    }

    public function test_winner_route_returns_404_when_winner_belongs_to_other_game(): void
    {
        [$gameA, $admin] = $this->makeRunningGameWithSoldNumber();
        [$gameB] = $this->makeRunningGameWithSoldNumber();
        $this->performWinningRun($gameB, $admin);

        // Winner exists in gameB; gameA has none.
        $this->getJson("/api/v1/admin/games/{$gameA->id}/winner")
            ->assertStatus(404)
            ->assertJsonPath('message', 'game_winner_not_found');

        $this->getJson("/api/v1/admin/games/{$gameB->id}/winner")
            ->assertOk();
    }

    public function test_filters_cannot_widen_scope_beyond_route_game(): void
    {
        [$gameA, $admin] = $this->makeRunningGameWithSoldNumber();
        [$gameB] = $this->makeRunningGameWithSoldNumber();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([2]));
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/games/{$gameB->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])->assertStatus(201);

        // No filter / query string can broaden the scope — game_id is not
        // an accepted filter and is taken from the route binding.
        $resp = $this->getJson("/api/v1/admin/games/{$gameA->id}/draws?number=2")->assertOk();
        $this->assertSame(0, count($resp->json('data')));
    }
}
