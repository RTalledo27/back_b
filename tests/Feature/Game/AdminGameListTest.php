<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminGameListTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/games')
            ->assertUnauthorized();
    }

    public function test_list_requires_admin_role(): void
    {
        $player = User::factory()->create(['role' => 'player']);

        $this->actingAs($player)
            ->getJson('/api/v1/admin/games')
            ->assertForbidden();
    }

    // ── Status coverage ───────────────────────────────────────────────────────

    public function test_list_returns_all_statuses_including_draft_and_cancelled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (GameStatus::cases() as $status) {
            $this->createGame($status);
        }

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games')
            ->assertOk();

        $this->assertCount(count(GameStatus::cases()), $response->json('data'));
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    public function test_list_filters_by_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->createGame(GameStatus::Draft, ['slug' => 'draft-game']);
        $this->createGame(GameStatus::SalesOpen, ['slug' => 'open-game-1']);
        $this->createGame(GameStatus::SalesOpen, ['slug' => 'open-game-2']);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?status=sales_open')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame('sales_open', $response->json('data.0.status'));
    }

    public function test_list_invalid_status_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?status=nonexistent')
            ->assertUnprocessable();
    }

    public function test_list_searches_by_partial_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->createGame(GameStatus::Draft, ['slug' => 'alpha-game', 'name' => 'Alpha Sorteo']);
        $this->createGame(GameStatus::Draft, ['slug' => 'beta-game', 'name' => 'Beta Sorteo']);
        $this->createGame(GameStatus::Draft, ['slug' => 'gamma-game', 'name' => 'Gamma Game']);

        $ids = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?search=Sorteo')
            ->assertOk()
            ->json('data.*.id');

        $this->assertCount(2, $ids);
    }

    public function test_list_searches_by_slug(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->createGame(GameStatus::Draft, ['slug' => 'bingo-navidad', 'name' => 'X']);
        $this->createGame(GameStatus::Draft, ['slug' => 'bingo-verano', 'name' => 'Y']);
        $this->createGame(GameStatus::Draft, ['slug' => 'sorteo-fin', 'name' => 'Z']);

        $ids = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?search=bingo')
            ->assertOk()
            ->json('data.*.id');

        $this->assertCount(2, $ids);
    }

    /**
     * published=true uses the same explicit whitelist as ListPublicGamesQuery.
     * Draft and Cancelled must be absent; at least SalesOpen and Running present.
     */
    public function test_list_published_true_excludes_draft_and_cancelled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $draft = $this->createGame(GameStatus::Draft, ['slug' => 'draft-pub']);
        $cancelled = $this->createGame(GameStatus::Cancelled, ['slug' => 'cancelled-pub']);
        $open = $this->createGame(GameStatus::SalesOpen, ['slug' => 'open-pub']);
        $running = $this->createGame(GameStatus::Running, ['slug' => 'running-pub', 'started_at' => now()->subHour()]);

        $ids = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?published=1')
            ->assertOk()
            ->json('data.*.id');

        $this->assertContains($open->id, $ids);
        $this->assertContains($running->id, $ids);
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    /**
     * published=true must include ALL 7 statuses in the public whitelist, not
     * just any subset. This mirrors the exact set from ListPublicGamesQuery and
     * confirms the admin filter uses whereIn (not a fragile whereNotIn) so that
     * a future new status doesn't leak into the public set silently.
     */
    public function test_list_published_true_matches_all_public_statuses(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $publicGames = [
            $this->createGame(GameStatus::Published, ['slug' => 'pp-published']),
            $this->createGame(GameStatus::SalesOpen, ['slug' => 'pp-sales-open']),
            $this->createGame(GameStatus::SalesClosed, ['slug' => 'pp-sales-closed']),
            $this->createGame(GameStatus::Running, ['slug' => 'pp-running', 'started_at' => now()->subHour()]),
            $this->createGame(GameStatus::Paused, ['slug' => 'pp-paused', 'started_at' => now()->subHour(), 'paused_at' => now()->subMinutes(5)]),
            $this->createGame(GameStatus::Resolving, ['slug' => 'pp-resolving', 'started_at' => now()->subHour()]),
            $this->createGame(GameStatus::Completed, ['slug' => 'pp-completed', 'started_at' => now()->subHour(), 'completed_at' => now()]),
        ];
        $draft = $this->createGame(GameStatus::Draft, ['slug' => 'pp-draft']);
        $cancelled = $this->createGame(GameStatus::Cancelled, ['slug' => 'pp-cancelled']);

        $ids = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?published=1&per_page=100')
            ->assertOk()
            ->json('data.*.id');

        foreach ($publicGames as $game) {
            $this->assertContains($game->id, $ids, "Status '{$game->status->value}' should be included with published=1.");
        }
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
        $this->assertCount(count($publicGames), $ids);
    }

    /**
     * published=false returns statuses NOT in the public whitelist.
     * With current GameStatus enum that means only draft and cancelled.
     */
    public function test_list_published_false_returns_only_draft_and_cancelled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->createGame(GameStatus::Draft, ['slug' => 'only-draft']);
        $this->createGame(GameStatus::Cancelled, ['slug' => 'only-cancelled']);
        $this->createGame(GameStatus::SalesOpen, ['slug' => 'not-visible']);

        $statuses = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?published=0')
            ->assertOk()
            ->json('data.*.status');

        foreach ($statuses as $s) {
            $this->assertContains($s, ['draft', 'cancelled']);
        }
    }

    public function test_list_filters_by_auto_draw_enabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->createGame(GameStatus::Draft, ['slug' => 'auto-on', 'auto_draw_enabled' => true]);
        $this->createGame(GameStatus::Draft, ['slug' => 'auto-off', 'auto_draw_enabled' => false]);

        $idsOn = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?auto_draw_enabled=1')
            ->assertOk()
            ->json('data.*.slug');
        $this->assertContains('auto-on', $idsOn);
        $this->assertNotContains('auto-off', $idsOn);

        $idsOff = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?auto_draw_enabled=0')
            ->assertOk()
            ->json('data.*.slug');
        $this->assertContains('auto-off', $idsOff);
        $this->assertNotContains('auto-on', $idsOff);
    }

    public function test_list_invalid_auto_draw_enabled_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?auto_draw_enabled=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('auto_draw_enabled');
    }

    public function test_list_combination_of_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->createGame(GameStatus::SalesOpen, ['slug' => 'combo-match', 'name' => 'Sorteo Verano', 'auto_draw_enabled' => true]);
        $this->createGame(GameStatus::SalesOpen, ['slug' => 'combo-no-auto', 'name' => 'Sorteo Verano', 'auto_draw_enabled' => false]);
        $this->createGame(GameStatus::Draft, ['slug' => 'combo-draft', 'name' => 'Sorteo Verano', 'auto_draw_enabled' => true]);

        $ids = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?status=sales_open&auto_draw_enabled=1&search=Sorteo')
            ->assertOk()
            ->json('data.*.slug');

        $this->assertContains('combo-match', $ids);
        $this->assertNotContains('combo-no-auto', $ids);
        $this->assertNotContains('combo-draft', $ids);
    }

    public function test_list_filters_by_created_date_range(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $old = $this->createGame(GameStatus::Draft, ['slug' => 'old-game']);
        $old->forceFill(['created_at' => Carbon::parse('2026-01-01')->utc()])->saveQuietly();

        $new = $this->createGame(GameStatus::Draft, ['slug' => 'new-game']);
        $new->forceFill(['created_at' => Carbon::parse('2026-06-01')->utc()])->saveQuietly();

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?created_from=2026-05-01')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($new->id));
        $this->assertFalse($ids->contains($old->id));
    }

    public function test_list_invalid_date_range_order_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?created_from=2026-12-01&created_to=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('created_to');
    }

    // ── Ordering ──────────────────────────────────────────────────────────────

    public function test_list_is_ordered_by_created_at_desc(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $first = $this->createGame(GameStatus::Draft, ['slug' => 'first-game']);
        $first->forceFill(['created_at' => Carbon::parse('2026-01-01')->utc()])->saveQuietly();

        $second = $this->createGame(GameStatus::Draft, ['slug' => 'second-game']);
        $second->forceFill(['created_at' => Carbon::parse('2026-06-01')->utc()])->saveQuietly();

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games')
            ->assertOk();

        $this->assertSame($second->id, $response->json('data.0.id'));
        $this->assertSame($first->id, $response->json('data.1.id'));
    }

    public function test_list_stable_ordering_when_same_created_at(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $ts = Carbon::parse('2026-06-01T10:00:00')->utc();
        $a = $this->createGame(GameStatus::Draft, ['slug' => 'tie-a']);
        $a->forceFill(['created_at' => $ts])->saveQuietly();

        $b = $this->createGame(GameStatus::Draft, ['slug' => 'tie-b']);
        $b->forceFill(['created_at' => $ts])->saveQuietly();

        // Both have same created_at — secondary sort by id DESC (UUIDv7 is time-ordered,
        // so the later-created record has a lexicographically larger UUID).
        $ids = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games')
            ->assertOk()
            ->json('data.*.id');

        // Result must be deterministic: same request, same order every time.
        $ids2 = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame($ids, $ids2);
    }

    // ── Contract / fields ─────────────────────────────────────────────────────

    public function test_list_summary_contains_expected_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createGame(GameStatus::SalesOpen, ['slug' => 'field-check']);

        $data = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games')
            ->assertOk()
            ->json('data.0');

        foreach (['id', 'slug', 'name', 'description', 'status', 'number_range', 'ticket_price', 'prize', 'schedule', 'lifecycle', 'numbers', 'ops', 'created_at'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        foreach (['draws_total', 'orders_pending', 'payments_under_review', 'entries_confirmed'] as $opsKey) {
            $this->assertArrayHasKey($opsKey, $data['ops']);
        }
    }

    public function test_list_summary_does_not_expose_settings_or_engine_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createGame(GameStatus::Draft, [
            'slug' => 'draft-hidden',
            'settings' => ['secret_key' => 'hidden'],
        ]);

        $json = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games')
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('"settings"', $json);
        $this->assertStringNotContainsString('secret_key', $json);
        $this->assertStringNotContainsString('last_consumed_tick_at', $json);
        $this->assertStringNotContainsString('next_draw_at', $json);
    }

    // ── Number aggregates ─────────────────────────────────────────────────────

    public function test_list_includes_number_aggregate_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::SalesOpen, ['slug' => 'with-numbers']);

        GameNumber::create(['game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold]);
        GameNumber::create(['game_id' => $game->id, 'number' => 2, 'status' => GameNumberStatus::Sold]);
        GameNumber::create(['game_id' => $game->id, 'number' => 3, 'status' => GameNumberStatus::Reserved]);
        GameNumber::create(['game_id' => $game->id, 'number' => 4, 'status' => GameNumberStatus::Available]);

        $numbers = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games')
            ->assertOk()
            ->json('data.0.numbers');

        $this->assertSame(10, $numbers['total']); // number_max(10) - number_min(1) + 1
        $this->assertSame(2, $numbers['sold']);
        $this->assertSame(1, $numbers['reserved']);
        $this->assertSame(1, $numbers['available']);
    }

    // ── Operative aggregates (ops) ────────────────────────────────────────────

    public function test_list_ops_includes_draws_orders_payments_entries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'player']);
        $game = $this->createGame(GameStatus::Running, ['slug' => 'ops-game', 'started_at' => now()->subHour()]);

        $number = GameNumber::create(['game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold]);

        // 2 draws (drawn_number must match game_numbers.number = 1)
        foreach ([1, 2] as $seq) {
            GameDraw::create([
                'game_id' => $game->id,
                'game_number_id' => $number->id,
                'sequence' => $seq,
                'drawn_number' => $number->number,
                'drawn_at' => now(),
                'strategy' => 'manual',
            ]);
        }

        // 1 pending order
        $order = Order::create([
            'user_id' => $player->id,
            'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500,
            'total_cents' => 500,
            'currency' => 'PEN',
        ]);

        // 1 payment under review
        Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::UnderReview,
        ]);

        // 1 confirmed entry
        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => $player->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now()->subMinute(),
        ]);

        $ops = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games')
            ->assertOk()
            ->json('data.0.ops');

        $this->assertSame(2, $ops['draws_total']);
        $this->assertSame(1, $ops['orders_pending']);
        $this->assertSame(1, $ops['payments_under_review']);
        $this->assertSame(1, $ops['entries_confirmed']);
    }

    public function test_list_ops_does_not_count_other_games_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'player']);

        $gameA = $this->createGame(GameStatus::Running, ['slug' => 'game-a', 'started_at' => now()->subHour()]);
        $gameB = $this->createGame(GameStatus::Running, ['slug' => 'game-b', 'started_at' => now()->subHour()]);

        $numA = GameNumber::create(['game_id' => $gameA->id, 'number' => 1, 'status' => GameNumberStatus::Sold]);
        $numB = GameNumber::create(['game_id' => $gameB->id, 'number' => 1, 'status' => GameNumberStatus::Sold]);

        // 3 draws for game B only (drawn_number must match game_numbers.number = 1)
        foreach ([1, 2, 3] as $seq) {
            GameDraw::create([
                'game_id' => $gameB->id,
                'game_number_id' => $numB->id,
                'sequence' => $seq,
                'drawn_number' => $numB->number,
                'drawn_at' => now(),
                'strategy' => 'manual',
            ]);
        }

        // Pending order for game B only
        Order::create([
            'user_id' => $player->id,
            'game_id' => $gameB->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500,
            'total_cents' => 500,
            'currency' => 'PEN',
        ]);

        $data = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?status=running')
            ->assertOk()
            ->json('data');

        $bySlug = collect($data)->keyBy('slug');

        $this->assertSame(0, $bySlug['game-a']['ops']['draws_total']);
        $this->assertSame(0, $bySlug['game-a']['ops']['orders_pending']);
        $this->assertSame(3, $bySlug['game-b']['ops']['draws_total']);
        $this->assertSame(1, $bySlug['game-b']['ops']['orders_pending']);
    }

    // ── Pagination ────────────────────────────────────────────────────────────

    public function test_list_per_page_is_bounded_at_100(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?per_page=200')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('per_page');
    }

    public function test_list_pagination_meta_is_present(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createGame(GameStatus::Draft, ['slug' => 'paged-game']);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/games?per_page=5')
            ->assertOk();

        $this->assertArrayHasKey('meta', $response->json());
        $this->assertArrayHasKey('links', $response->json());
    }

    // ── N+1 / query stability ─────────────────────────────────────────────────

    public function test_list_does_not_lazy_load_relations(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $game = $this->createGame(GameStatus::Running, ['slug' => 'no-lazy', 'started_at' => now()->subHour()]);
        GameNumber::create(['game_id' => $game->id, 'number' => 5, 'status' => GameNumberStatus::Sold]);

        Model::preventLazyLoading(true);

        $selects = 0;
        DB::listen(function ($q) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($q->sql)), 'select')) {
                $selects++;
            }
        });

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/games')
            ->assertOk();

        $this->assertLessThanOrEqual(5, $selects);
    }

    public function test_list_query_count_stable_across_multiple_games(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'player']);

        for ($i = 0; $i < 5; $i++) {
            $g = $this->createGame(GameStatus::Running, ['slug' => "multi-{$i}", 'started_at' => now()->subHour()]);
            $n = GameNumber::create(['game_id' => $g->id, 'number' => 1, 'status' => GameNumberStatus::Sold]);
            GameDraw::create([
                'game_id' => $g->id,
                'game_number_id' => $n->id,
                'sequence' => 1,
                'drawn_number' => 1,
                'drawn_at' => now(),
                'strategy' => 'manual',
            ]);
            Order::create([
                'user_id' => $player->id,
                'game_id' => $g->id,
                'status' => OrderStatus::Pending,
                'subtotal_cents' => 500,
                'total_cents' => 500,
                'currency' => 'PEN',
            ]);
        }

        Model::preventLazyLoading(true);

        $selects = 0;
        DB::listen(function ($q) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($q->sql)), 'select')) {
                $selects++;
            }
        });

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/games')
            ->assertOk();

        // With 5 games all their aggregates are computed in one paginated query.
        // Same bound as with 1 game — confirms no N+1.
        $this->assertLessThanOrEqual(5, $selects);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGame(GameStatus $status, array $overrides = []): Game
    {
        return Game::create(array_merge([
            'slug' => 'admin-game-'.uniqid(),
            'name' => 'Admin Game',
            'description' => 'A game',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 3,
            'ticket_price_cents' => 500,
            'prize_cents' => 3000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => $status,
        ], $overrides));
    }
}
