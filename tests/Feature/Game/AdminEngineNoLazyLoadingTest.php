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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

/**
 * Asserts no N+1 surprises in the engine read endpoints.
 *  - Enables Model::preventLazyLoading() locally (test scope only).
 *  - Uses DB::enableQueryLog() to put hard caps on the query counts
 *    of List* and Winner endpoints regardless of dataset shape.
 */
final class AdminEngineNoLazyLoadingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::preventLazyLoading(true);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    /**
     * @return array{Game, User, GameEntry}
     */
    private function makeWinnerScenario(): array
    {
        $game = Game::create([
            'slug' => 'nl-'.fake()->unique()->lexify('?????'),
            'name' => 'NL', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
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
        $admin = User::factory()->admin()->create();

        // Transition to Running and draw twice → winner.
        $game->status = GameStatus::Running;
        $game->started_at = now()->subSecond();
        $game->saveQuietly();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1, 1]));
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])->assertStatus(201);
        $this->postJson("/api/v1/admin/games/{$game->id}/draws", [], ['X-Draw-Command-Id' => (string) Str::uuid7()])->assertStatus(201);

        return [$game, $admin, $entry];
    }

    public function test_winner_endpoint_does_not_trigger_lazy_loading(): void
    {
        [$game] = $this->makeWinnerScenario();
        // Sanctum still acting as the admin from setup.
        DB::enableQueryLog();

        $this->getJson("/api/v1/admin/games/{$game->id}/winner")->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        // Sanctum + Game binding + GetGameWinnerQuery (with eager loads).
        // A reasonable cap that catches accidental N+1 over winner's
        // relations (entry, gameNumber, draw).
        $this->assertLessThanOrEqual(8, count($queries), 'Winner endpoint exceeded the N+1 budget: '.count($queries).' queries.');
    }

    public function test_draws_listing_query_count_is_bounded(): void
    {
        [$game, $admin] = $this->makeWinnerScenario();
        Sanctum::actingAs($admin);

        DB::enableQueryLog();
        $this->getJson("/api/v1/admin/games/{$game->id}/draws")->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Auth + paginator count + paginator page → comfortably below the
        // cap regardless of dataset size.
        $this->assertLessThanOrEqual(8, count($queries), 'Draws listing exceeded query budget: '.count($queries));
    }

    public function test_counters_listing_query_count_is_bounded(): void
    {
        [$game, $admin] = $this->makeWinnerScenario();
        Sanctum::actingAs($admin);

        DB::enableQueryLog();
        $this->getJson("/api/v1/admin/games/{$game->id}/counters")->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Single LEFT JOIN query for the page + count query for the meta.
        $this->assertLessThanOrEqual(8, count($queries), 'Counters listing exceeded query budget: '.count($queries));
    }
}
