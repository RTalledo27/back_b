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
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
}
