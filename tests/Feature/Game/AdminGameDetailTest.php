<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PaymentDocument;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminGameDetailTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function test_detail_requires_authentication(): void
    {
        $game = $this->createGame(GameStatus::Draft);

        $this->getJson("/api/v1/admin/games/{$game->id}")
            ->assertUnauthorized();
    }

    public function test_detail_requires_admin_role(): void
    {
        $player = User::factory()->create(['role' => 'player']);
        $game = $this->createGame(GameStatus::Draft);

        $this->actingAs($player)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertForbidden();
    }

    public function test_nonexistent_game_returns_404(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/games/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    // ── Contract / fields ─────────────────────────────────────────────────────

    public function test_detail_exposes_all_admin_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::SalesOpen, [
            'settings' => ['key' => 'value'],
            'sales_opens_at' => Carbon::parse('2026-06-01T10:00:00-05:00')->utc(),
        ]);

        $data = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->json('data');

        foreach ([
            'id', 'slug', 'name', 'description', 'status',
            'number_range', 'ticket_price', 'prize', 'schedule',
            'lifecycle', 'engine', 'numbers', 'settings',
            'latest_draw', 'winner', 'commerce', 'projection',
            'created_by', 'created_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "Field '{$key}' missing from detail response.");
        }
    }

    public function test_detail_includes_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Draft, [
            'settings' => ['engine_mode' => 'auto', 'max_ticks' => 500],
        ]);

        $settings = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->json('data.settings');

        $this->assertSame('auto', $settings['engine_mode']);
        $this->assertSame(500, $settings['max_ticks']);
    }

    public function test_detail_includes_engine_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Running, [
            'started_at' => now()->subHour(),
            'next_draw_at' => Carbon::parse('2026-06-25T15:00:00+00:00'),
            'last_consumed_tick_at' => Carbon::parse('2026-06-25T14:59:30+00:00'),
        ]);

        $engine = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->json('data.engine');

        $this->assertSame('2026-06-25T15:00:00+00:00', $engine['next_draw_at']);
        $this->assertSame('2026-06-25T14:59:30+00:00', $engine['last_consumed_tick_at']);
    }

    public function test_detail_includes_number_aggregate_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Running);

        GameNumber::create(['game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold]);
        GameNumber::create(['game_id' => $game->id, 'number' => 2, 'status' => GameNumberStatus::Sold]);
        GameNumber::create(['game_id' => $game->id, 'number' => 3, 'status' => GameNumberStatus::Reserved]);
        GameNumber::create(['game_id' => $game->id, 'number' => 4, 'status' => GameNumberStatus::Available]);

        $numbers = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->json('data.numbers');

        $this->assertSame(10, $numbers['total']);
        $this->assertSame(2, $numbers['sold']);
        $this->assertSame(1, $numbers['reserved']);
        $this->assertSame(1, $numbers['available']);
    }

    // ── Commerce aggregates ───────────────────────────────────────────────────

    public function test_detail_commerce_aggregates_by_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'player']);
        $game = $this->createGame(GameStatus::Running, ['started_at' => now()->subHour()]);

        // game_entries has a unique constraint on game_number_id — use separate numbers
        $num1 = GameNumber::create(['game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold]);
        $num2 = GameNumber::create(['game_id' => $game->id, 'number' => 2, 'status' => GameNumberStatus::Sold]);

        // Orders by status
        $pendingOrder = $this->createOrder($game->id, $player->id, OrderStatus::Pending);
        $paidOrder = $this->createOrder($game->id, $player->id, OrderStatus::Paid);
        $this->createOrder($game->id, $player->id, OrderStatus::Cancelled);

        // Payments through orders
        Payment::create([
            'order_id' => $pendingOrder->id,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::UnderReview,
        ]);
        Payment::create([
            'order_id' => $paidOrder->id,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Approved,
        ]);

        // Entries by status (one number per entry due to unique constraint)
        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $num1->id,
            'user_id' => $player->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now()->subMinute(),
        ]);
        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $num2->id,
            'user_id' => $player->id,
            'status' => EntryStatus::Cancelled,
            'confirmed_at' => now()->subMinutes(2),
        ]);

        // Reservation for num1
        NumberReservation::create([
            'order_id' => $pendingOrder->id,
            'game_number_id' => $num1->id,
        ]);

        $commerce = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->json('data.commerce');

        // Orders
        $this->assertSame(1, $commerce['orders']['pending']);
        $this->assertSame(0, $commerce['orders']['payment_submitted']);
        $this->assertSame(1, $commerce['orders']['paid']);
        $this->assertSame(0, $commerce['orders']['rejected']);
        $this->assertSame(0, $commerce['orders']['expired']);
        $this->assertSame(1, $commerce['orders']['cancelled']);
        $this->assertSame(0, $commerce['orders']['refunded']);

        // Payments
        $this->assertSame(0, $commerce['payments']['pending']);
        $this->assertSame(1, $commerce['payments']['under_review']);
        $this->assertSame(1, $commerce['payments']['approved']);
        $this->assertSame(0, $commerce['payments']['rejected']);
        $this->assertSame(0, $commerce['payments']['cancelled']);
        $this->assertSame(0, $commerce['payments']['refunded']);

        // Entries
        $this->assertSame(1, $commerce['entries']['confirmed']);
        $this->assertSame(1, $commerce['entries']['cancelled']);
        $this->assertSame(0, $commerce['entries']['refunded']);
        $this->assertSame(0, $commerce['entries']['winner']);

        // Reservations
        $this->assertSame(1, $commerce['reservations']['total']);
    }

    /**
     * NumberReservation has no status column — only id, order_id, game_number_id.
     * Reservation lifecycle is implicit from Order.status (single source of truth).
     * Therefore commerce.reservations exposes only `total`; no status breakdowns exist.
     */
    public function test_detail_reservations_has_total_only_no_status_breakdown(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'player']);
        $game = $this->createGame(GameStatus::Running, ['started_at' => now()->subHour()]);

        $num1 = GameNumber::create(['game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Reserved]);
        $num2 = GameNumber::create(['game_id' => $game->id, 'number' => 2, 'status' => GameNumberStatus::Reserved]);
        $order = $this->createOrder($game->id, $player->id, OrderStatus::Pending);

        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $num1->id]);
        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $num2->id]);

        $reservations = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->json('data.commerce.reservations');

        $this->assertSame(2, $reservations['total']);
        // No status keys exist — the schema has no status column on number_reservations
        $this->assertSame(['total'], array_keys($reservations));
    }

    /**
     * Reservations from other games must not be counted.
     */
    public function test_detail_reservations_do_not_include_other_games(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'player']);

        $gameA = $this->createGame(GameStatus::Running, ['slug' => 'rv-game-a', 'started_at' => now()->subHour()]);
        $gameB = $this->createGame(GameStatus::Running, ['slug' => 'rv-game-b', 'started_at' => now()->subHour()]);

        // 3 reservations for game B only
        $orderB = $this->createOrder($gameB->id, $player->id, OrderStatus::Pending);
        foreach ([10, 11, 12] as $num) {
            $nb = GameNumber::create(['game_id' => $gameB->id, 'number' => $num, 'status' => GameNumberStatus::Reserved]);
            NumberReservation::create(['order_id' => $orderB->id, 'game_number_id' => $nb->id]);
        }

        $reservations = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$gameA->id}")
            ->assertOk()
            ->json('data.commerce.reservations');

        $this->assertSame(0, $reservations['total']);
    }

    public function test_detail_commerce_does_not_count_other_games(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'player']);

        $gameA = $this->createGame(GameStatus::Running, ['slug' => 'ca-game-a', 'started_at' => now()->subHour()]);
        $gameB = $this->createGame(GameStatus::Running, ['slug' => 'ca-game-b', 'started_at' => now()->subHour()]);

        // 3 pending orders for game B only
        foreach (range(1, 3) as $_) {
            $this->createOrder($gameB->id, $player->id, OrderStatus::Pending);
        }

        $commerce = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$gameA->id}")
            ->assertOk()
            ->json('data.commerce');

        $this->assertSame(0, $commerce['orders']['pending']);
    }

    // ── Projection ────────────────────────────────────────────────────────────

    public function test_detail_projection_section(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Running, ['started_at' => now()->subHour()]);

        $num5 = GameNumber::create(['game_id' => $game->id, 'number' => 5, 'status' => GameNumberStatus::Sold]);
        $num7 = GameNumber::create(['game_id' => $game->id, 'number' => 7, 'status' => GameNumberStatus::Sold]);

        // 3 draws: numbers 5, 7, 5 — 2 distinct (5 and 7)
        GameDraw::create(['game_id' => $game->id, 'game_number_id' => $num5->id, 'sequence' => 1, 'drawn_number' => 5, 'drawn_at' => now()->subMinutes(3), 'strategy' => 'manual']);
        GameDraw::create(['game_id' => $game->id, 'game_number_id' => $num7->id, 'sequence' => 2, 'drawn_number' => 7, 'drawn_at' => now()->subMinutes(2), 'strategy' => 'manual']);
        GameDraw::create(['game_id' => $game->id, 'game_number_id' => $num5->id, 'sequence' => 3, 'drawn_number' => 5, 'drawn_at' => now()->subMinute(), 'strategy' => 'manual']);

        // Counter rows (HasUuids model — use ::create to get UUID auto-generated)
        GameNumberCounter::create(['game_id' => $game->id, 'game_number_id' => $num5->id, 'hits_count' => 2]);
        GameNumberCounter::create(['game_id' => $game->id, 'game_number_id' => $num7->id, 'hits_count' => 1]);

        $projection = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->json('data.projection');

        $this->assertSame(3, $projection['draws_total']);
        $this->assertSame(2, $projection['distinct_drawn_numbers']);
        $this->assertSame(2, $projection['max_counter_hits']);
        $this->assertSame(5, $projection['last_drawn_number']); // last by sequence
    }

    public function test_detail_projection_zeros_when_no_draws(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Draft);

        $projection = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->json('data.projection');

        $this->assertSame(0, $projection['draws_total']);
        $this->assertSame(0, $projection['distinct_drawn_numbers']);
        $this->assertSame(0, $projection['max_counter_hits']);
        $this->assertNull($projection['last_drawn_number']);
    }

    // ── Latest draw / winner ──────────────────────────────────────────────────

    public function test_detail_includes_latest_draw_when_present(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Running, ['started_at' => now()->subHour()]);
        $number = GameNumber::create(['game_id' => $game->id, 'number' => 7, 'status' => GameNumberStatus::Sold]);

        GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => 1,
            'drawn_number' => 7,
            'drawn_at' => Carbon::parse('2026-06-25T12:00:00+00:00'),
            'strategy' => 'manual',
        ]);

        $draw = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->json('data.latest_draw');

        $this->assertSame(1, $draw['sequence']);
        $this->assertSame(7, $draw['number']);
        $this->assertSame('2026-06-25T12:00:00+00:00', $draw['drawn_at']);
    }

    public function test_detail_latest_draw_is_null_when_no_draws(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Draft);

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.latest_draw', null);
    }

    public function test_detail_includes_winner_when_game_is_completed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $winner_user = User::factory()->create(['role' => 'player']);
        $game = $this->createGame(GameStatus::Completed, [
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
        $number = GameNumber::create(['game_id' => $game->id, 'number' => 9, 'status' => GameNumberStatus::Sold]);
        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => 5,
            'drawn_number' => 9,
            'drawn_at' => Carbon::parse('2026-06-25T12:05:00+00:00'),
            'strategy' => 'manual',
        ]);
        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => $winner_user->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => now()->subMinutes(30),
        ]);
        GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $number->id,
            'user_id' => $winner_user->id,
            'winning_hits' => 3,
            'won_at' => Carbon::parse('2026-06-25T12:05:00+00:00'),
        ]);

        $winnerData = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->json('data.winner');

        $this->assertSame($winner_user->id, $winnerData['user_id']);
        $this->assertSame(9, $winnerData['winning_number']);
        $this->assertSame(5, $winnerData['winning_draw_sequence']);
        $this->assertSame(3, $winnerData['winning_hits']);
        $this->assertSame('2026-06-25T12:05:00+00:00', $winnerData['won_at']);
    }

    public function test_detail_winner_is_null_when_game_not_completed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Running, ['started_at' => now()->subHour()]);

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.winner', null);
    }

    // ── Lifecycle access ──────────────────────────────────────────────────────

    public function test_detail_accessible_for_draft_game(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Draft);

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_detail_accessible_for_cancelled_game(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Cancelled);

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    // ── Privacy ───────────────────────────────────────────────────────────────

    public function test_detail_winner_does_not_expose_player_pii(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $winner_user = User::factory()->create(['role' => 'player', 'email' => 'winner@example.com', 'name' => 'Jane Doe']);
        $game = $this->createGame(GameStatus::Completed, [
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);
        $number = GameNumber::create(['game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold]);
        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => 1,
            'drawn_number' => 1,
            'drawn_at' => now(),
            'strategy' => 'manual',
        ]);
        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => $winner_user->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => now()->subMinute(),
        ]);
        GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $number->id,
            'user_id' => $winner_user->id,
            'winning_hits' => 1,
            'won_at' => now(),
        ]);

        $json = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('winner@example.com', $json);
        $this->assertStringNotContainsString('Jane Doe', $json);
        $this->assertStringNotContainsString('"password"', $json);
        $this->assertStringNotContainsString('"remember_token"', $json);
    }

    public function test_detail_does_not_expose_player_pii_via_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create([
            'role' => 'player',
            'email' => 'orderer@example.com',
            'name' => 'John Player',
        ]);
        $game = $this->createGame(GameStatus::Running, ['started_at' => now()->subHour()]);

        $this->createOrder($game->id, $player->id, OrderStatus::Pending);

        $json = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('orderer@example.com', $json);
        $this->assertStringNotContainsString('John Player', $json);
        $this->assertStringNotContainsString('"password"', $json);
        $this->assertStringNotContainsString('"remember_token"', $json);
    }

    public function test_detail_does_not_expose_payment_evidence_paths(): void
    {
        // Evidence is stored in payment_documents (disk, path, original_filename, sha256).
        // The commerce section exposes only aggregate counts — raw document data must not leak.
        $admin = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'player']);
        $game = $this->createGame(GameStatus::Running, ['started_at' => now()->subHour()]);

        $order = $this->createOrder($game->id, $player->id, OrderStatus::PaymentSubmitted);
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::UnderReview,
        ]);
        PaymentDocument::create([
            'payment_id' => $payment->id,
            'disk' => 'local',
            'path' => '/private/payments/evidence/xyz.jpg',
            'original_filename' => 'transfer_proof.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 102400,
            'sha256' => 'abc123def456abc123def456abc123def456abc123def456abc123def456abc1',
            'uploaded_by' => $player->id,
        ]);

        $json = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('"disk"', $json);
        $this->assertStringNotContainsString('"path"', $json);
        $this->assertStringNotContainsString('"original_filename"', $json);
        $this->assertStringNotContainsString('/private/payments', $json);
        $this->assertStringNotContainsString('transfer_proof.jpg', $json);
        $this->assertStringNotContainsString('"sha256"', $json);
    }

    public function test_detail_does_not_expose_idempotency_keys(): void
    {
        // The response commerce section must never expose an idempotency_key field,
        // even if such data existed. This asserts the field name is absent from JSON.
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Draft);

        $json = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('"idempotency_key"', $json);
    }

    public function test_detail_does_not_expose_payment_rejection_reason_or_reviewer(): void
    {
        // Payment's rejection_reason and reviewed_by are admin-internal fields that
        // must not appear in the game detail response (only counts are exposed).
        $admin = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin']);
        $player = User::factory()->create(['role' => 'player']);
        $game = $this->createGame(GameStatus::Running, ['started_at' => now()->subHour()]);

        $order = $this->createOrder($game->id, $player->id, OrderStatus::Rejected);
        Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now()->subMinute(),
            'rejection_reason' => 'Blurry photo, resubmit please',
        ]);

        $json = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('"rejection_reason"', $json);
        $this->assertStringNotContainsString('Blurry photo', $json);
        $this->assertStringNotContainsString('"reviewed_by"', $json);
    }

    public function test_detail_does_not_expose_oauth_attempts_or_tokens(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Draft);

        $json = $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk()
            ->content();

        foreach (['oauth_attempt', 'exchange_code', 'state_hash', 'access_token', 'refresh_token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    // ── N+1 / query stability ─────────────────────────────────────────────────

    public function test_detail_does_not_lazy_load_relations(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $game = $this->createGame(GameStatus::Running, ['started_at' => now()->subHour()]);
        $number = GameNumber::create(['game_id' => $game->id, 'number' => 3, 'status' => GameNumberStatus::Sold]);
        GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => 1,
            'drawn_number' => 3,
            'drawn_at' => now(),
            'strategy' => 'manual',
        ]);

        Model::preventLazyLoading(true);

        $selects = 0;
        DB::listen(function ($q) use (&$selects): void {
            if (str_starts_with(strtolower(ltrim($q->sql)), 'select')) {
                $selects++;
            }
        });

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/games/{$game->id}")
            ->assertOk();

        // Auth + game (with eager loads + withCount) + latestDraw + winner(null) +
        // computeCommerce (4) + computeProjection (2)
        $this->assertLessThanOrEqual(16, $selects);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGame(GameStatus $status, array $overrides = []): Game
    {
        return Game::create(array_merge([
            'slug' => 'admin-detail-'.uniqid(),
            'name' => 'Admin Detail Game',
            'description' => 'Detail test game',
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrder(string $gameId, int|string $userId, OrderStatus $status, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $userId,
            'game_id' => $gameId,
            'status' => $status,
            'subtotal_cents' => 500,
            'total_cents' => 500,
            'currency' => 'PEN',
        ], $overrides));
    }
}
