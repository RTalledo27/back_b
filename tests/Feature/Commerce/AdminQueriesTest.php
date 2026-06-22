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
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AdminQueriesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_player_cannot_list_admin_payments(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/admin/payments')->assertStatus(403);
    }

    public function test_admin_lists_payments_filtered_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();
        $game = Game::create([
            'slug' => 'aq-'.fake()->unique()->lexify('?????'),
            'name' => 'AQ', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesOpen,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::PaymentSubmitted,
            'subtotal_cents' => 500, 'total_cents' => 500,
            'currency' => 'PEN', 'expires_at' => null,
        ]);
        Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::UnderReview,
            'submitted_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/payments?status=under_review')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'under_review');

        // Unknown status -> filter ignored, returns all
        $this->getJson('/api/v1/admin/payments?status=__bogus__')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_lists_orders_filtered_by_status_and_game(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();
        $game1 = Game::create(['slug' => 'g1-'.fake()->unique()->lexify('????'), 'name' => 'g1',
            'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => true,
            'status' => GameStatus::SalesOpen]);
        $game2 = Game::create(['slug' => 'g2-'.fake()->unique()->lexify('????'), 'name' => 'g2',
            'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => true,
            'status' => GameStatus::SalesOpen]);

        Order::create(['user_id' => $buyer->id, 'game_id' => $game1->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 100, 'total_cents' => 100, 'currency' => 'PEN',
            'expires_at' => now()->addHour()]);
        Order::create(['user_id' => $buyer->id, 'game_id' => $game2->id,
            'status' => OrderStatus::Paid,
            'subtotal_cents' => 100, 'total_cents' => 100, 'currency' => 'PEN',
            'paid_at' => now()]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/orders?game_id={$game1->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');

        $this->getJson('/api/v1/admin/orders?status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'paid');
    }

    public function test_admin_lists_game_numbers_with_reservation_and_entry_details(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();
        $game = Game::create([
            'slug' => 'gn-'.fake()->unique()->lexify('????'), 'name' => 'GN',
            'number_min' => 1, 'number_max' => 3, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesOpen,
        ]);
        $reserved = GameNumber::create(['game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Reserved]);
        $available = GameNumber::create(['game_id' => $game->id, 'number' => 2, 'status' => GameNumberStatus::Available]);

        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::Pending, 'subtotal_cents' => 500, 'total_cents' => 500,
            'currency' => 'PEN', 'expires_at' => now()->addHour(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'game_number_id' => $reserved->id, 'unit_price_cents' => 500]);
        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $reserved->id]);

        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/v1/admin/games/{$game->id}/numbers")->assertOk();

        $data = $response->json('data');
        $byNumber = collect($data)->keyBy('number');

        $this->assertSame('reserved', $byNumber[1]['status']);
        $this->assertNotNull($byNumber[1]['active_reservation']);
        $this->assertSame($order->id, $byNumber[1]['active_reservation']['order_id']);
        $this->assertSame($buyer->id, $byNumber[1]['active_reservation']['user_id']);
        $this->assertNull($byNumber[1]['sold_entry']);

        $this->assertSame('available', $byNumber[2]['status']);
        $this->assertNull($byNumber[2]['active_reservation']);
        $this->assertNull($byNumber[2]['sold_entry']);
    }
}
