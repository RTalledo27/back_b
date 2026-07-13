<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PlayerQueriesTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, GameNumber, GameNumber}
     */
    private function setupGameWithNumbers(): array
    {
        $game = Game::create([
            'slug' => 'pq-'.fake()->unique()->lexify('?????'),
            'name' => 'PQ',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesOpen,
        ]);
        $gn1 = GameNumber::create(['game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Reserved]);
        $gn2 = GameNumber::create(['game_id' => $game->id, 'number' => 2, 'status' => GameNumberStatus::Reserved]);

        return [$game, $gn1, $gn2];
    }

    private function createPendingOrderFor(User $user, Game $game, GameNumber ...$gns): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500 * count($gns),
            'total_cents' => 500 * count($gns),
            'currency' => 'PEN', 'expires_at' => now()->addMinutes(10),
        ]);
        foreach ($gns as $gn) {
            OrderItem::create(['order_id' => $order->id, 'game_number_id' => $gn->id, 'unit_price_cents' => 500]);
            NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $gn->id]);
        }
        Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500 * count($gns),
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);

        return $order;
    }

    public function test_me_reservations_only_returns_owned(): void
    {
        [$game, $gn1, $gn2] = $this->setupGameWithNumbers();
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->createPendingOrderFor($me, $game, $gn1);
        $this->createPendingOrderFor($other, $game, $gn2);

        Sanctum::actingAs($me);
        $response = $this->getJson('/api/v1/me/reservations')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($gn1->id, $response->json('data.0.game_number_id'));
    }

    public function test_me_orders_only_returns_owned(): void
    {
        [$game, $gn1, $gn2] = $this->setupGameWithNumbers();
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mineOrder = $this->createPendingOrderFor($me, $game, $gn1);
        $this->createPendingOrderFor($other, $game, $gn2);

        Sanctum::actingAs($me);
        $response = $this->getJson('/api/v1/me/orders')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mineOrder->id, $response->json('data.0.id'));
    }

    public function test_me_orders_status_filter_is_allow_listed(): void
    {
        [$game, $gn1, $gn2] = $this->setupGameWithNumbers();
        $me = User::factory()->create();
        $this->createPendingOrderFor($me, $game, $gn1);
        $cancelled = $this->createPendingOrderFor($me, $game, $gn2);
        $cancelled->status = OrderStatus::Cancelled;
        $cancelled->saveQuietly();

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/me/orders?status=cancelled')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'cancelled');

        // Unknown filter values are silently ignored — returns everything.
        $this->getJson('/api/v1/me/orders?status=__not_a_real_status__')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_show_my_order_returns_404_for_other_user_order(): void
    {
        [$game, $gn1] = $this->setupGameWithNumbers();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $order = $this->createPendingOrderFor($owner, $game, $gn1);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/me/orders/{$order->id}")->assertNotFound();

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/me/orders/{$order->id}")->assertOk();
    }

    public function test_me_entries_only_returns_owned(): void
    {
        [$game, $gn1, $gn2] = $this->setupGameWithNumbers();
        $me = User::factory()->create();
        $other = User::factory()->create();

        GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id, 'user_id' => $me->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);
        GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn2->id, 'user_id' => $other->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($me);
        $response = $this->getJson('/api/v1/me/entries')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($gn1->id, $response->json('data.0.game_number_id'));
    }

    public function test_me_entries_include_live_progress_for_running_entries(): void
    {
        [$game, $gn1] = $this->setupGameWithNumbers();
        $game->status = GameStatus::Running;
        $game->started_at = Carbon::parse('2026-07-10 14:00:00+00:00');
        $game->saveQuietly();

        $me = User::factory()->create();
        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gn1->id,
            'user_id' => $me->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $gn1->id,
            'sequence' => 2,
            'drawn_number' => $gn1->number,
            'drawn_at' => Carbon::parse('2026-07-10 14:00:20+00:00'),
            'strategy' => 'test_strategy',
        ]);

        GameNumberCounter::create([
            'game_id' => $game->id,
            'game_number_id' => $gn1->id,
            'hits_count' => 2,
            'last_draw_sequence' => $draw->sequence,
        ]);

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/me/entries')
            ->assertOk()
            ->assertJsonPath('data.0.live_progress.entry_id', fn ($value) => is_string($value) && $value !== '')
            ->assertJsonPath('data.0.live_progress.game_id', $game->id)
            ->assertJsonPath('data.0.live_progress.game_status', 'running')
            ->assertJsonPath('data.0.live_progress.game_number', 1)
            ->assertJsonPath('data.0.live_progress.hits_current', 2)
            ->assertJsonPath('data.0.live_progress.hits_required', 5)
            ->assertJsonPath('data.0.live_progress.latest_draw_number', 1)
            ->assertJsonPath('data.0.live_progress.latest_draw_sequence', 2)
            ->assertJsonPath('data.0.live_progress.is_winner', false)
            ->assertJsonPath('data.0.live_progress.completed_at', null)
            ->assertJsonPath('data.0.live_progress.won_at', null);
    }

    public function test_me_entries_include_live_progress_for_completed_winner_entries(): void
    {
        [$game, $gn1] = $this->setupGameWithNumbers();
        $completedAt = Carbon::parse('2026-07-10 15:00:00+00:00');
        $game->status = GameStatus::Completed;
        $game->started_at = $completedAt->copy()->subMinutes(5);
        $game->completed_at = $completedAt;
        $game->saveQuietly();

        $me = User::factory()->create();
        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gn1->id,
            'user_id' => $me->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => now(),
        ]);

        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $gn1->id,
            'sequence' => 5,
            'drawn_number' => $gn1->number,
            'drawn_at' => $completedAt,
            'strategy' => 'test_strategy',
        ]);

        GameNumberCounter::create([
            'game_id' => $game->id,
            'game_number_id' => $gn1->id,
            'hits_count' => 5,
            'last_draw_sequence' => $draw->sequence,
        ]);

        GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $gn1->id,
            'user_id' => $me->id,
            'winning_hits' => 5,
            'won_at' => $completedAt,
        ]);

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/me/entries')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'winner')
            ->assertJsonPath('data.0.live_progress.game_status', 'completed')
            ->assertJsonPath('data.0.live_progress.hits_current', 5)
            ->assertJsonPath('data.0.live_progress.hits_required', 5)
            ->assertJsonPath('data.0.live_progress.latest_draw_number', 1)
            ->assertJsonPath('data.0.live_progress.latest_draw_sequence', 5)
            ->assertJsonPath('data.0.live_progress.is_winner', true)
            ->assertJsonPath('data.0.live_progress.completed_at', '2026-07-10T15:00:00+00:00')
            ->assertJsonPath('data.0.live_progress.won_at', '2026-07-10T15:00:00+00:00');
    }

    public function test_me_entries_include_zero_hits_and_null_latest_draw_when_game_has_not_drawn_yet(): void
    {
        [$game, $gn1] = $this->setupGameWithNumbers();
        $game->status = GameStatus::Running;
        $game->started_at = Carbon::parse('2026-07-10 16:00:00+00:00');
        $game->saveQuietly();

        $me = User::factory()->create();
        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gn1->id,
            'user_id' => $me->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/me/entries')
            ->assertOk()
            ->assertJsonPath('data.0.live_progress.hits_current', 0)
            ->assertJsonPath('data.0.live_progress.latest_draw_number', null)
            ->assertJsonPath('data.0.live_progress.latest_draw_sequence', null)
            ->assertJsonPath('data.0.live_progress.is_winner', false);
    }
}
