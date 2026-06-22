<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderCancelledByUser;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CancelOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{User, Order, Payment, list<GameNumber>}
     */
    private function setupPendingOrder(int $count = 2): array
    {
        $buyer = User::factory()->create();
        $game = Game::create([
            'slug' => 'co-'.fake()->unique()->lexify('?????'),
            'name' => 'CO',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesOpen,
        ]);
        $gns = [];
        for ($i = 1; $i <= $count; $i++) {
            $gns[] = GameNumber::create([
                'game_id' => $game->id, 'number' => $i,
                'status' => GameNumberStatus::Reserved,
            ]);
        }
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500 * $count, 'total_cents' => 500 * $count,
            'currency' => 'PEN', 'expires_at' => now()->addMinutes(10),
        ]);
        foreach ($gns as $gn) {
            OrderItem::create(['order_id' => $order->id, 'game_number_id' => $gn->id, 'unit_price_cents' => 500]);
            NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $gn->id]);
        }
        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500 * $count,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);

        return [$buyer, $order, $payment, $gns];
    }

    public function test_player_cancels_own_pending_order_releases_numbers_and_dispatches_event(): void
    {
        Event::fake([OrderCancelledByUser::class]);
        [$buyer, $order, $payment, $gns] = $this->setupPendingOrder(2);
        Sanctum::actingAs($buyer);

        $response = $this->postJson("/api/v1/me/orders/{$order->id}/cancel")->assertOk();
        $response->assertJsonPath('data.order.status', 'cancelled');
        $response->assertJsonPath('data.payment.status', 'cancelled');
        $this->assertCount(2, $response->json('data.released.numbers'));

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(PaymentStatus::Cancelled, $payment->refresh()->status);
        foreach ($gns as $gn) {
            $this->assertSame(GameNumberStatus::Available, $gn->refresh()->status);
        }
        $this->assertSame(0, NumberReservation::query()->where('order_id', $order->id)->count());

        Event::assertDispatched(OrderCancelledByUser::class, 1);
    }

    public function test_player_cannot_cancel_other_user_order(): void
    {
        [, $order] = $this->setupPendingOrder();
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/me/orders/{$order->id}/cancel")->assertStatus(403);
    }

    public function test_cannot_cancel_order_under_review(): void
    {
        [$buyer, $order, $payment] = $this->setupPendingOrder();
        $order->status = OrderStatus::PaymentSubmitted;
        $order->expires_at = null;
        $order->saveQuietly();
        $payment->status = PaymentStatus::UnderReview;
        $payment->submitted_at = now();
        $payment->saveQuietly();

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/me/orders/{$order->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_order_transition');
    }

    public function test_repeated_cancel_does_not_duplicate_audit_or_event(): void
    {
        Event::fake([OrderCancelledByUser::class]);
        [$buyer, $order] = $this->setupPendingOrder();
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/me/orders/{$order->id}/cancel")->assertOk();
        $this->postJson("/api/v1/me/orders/{$order->id}/cancel")->assertOk();

        Event::assertDispatched(OrderCancelledByUser::class, 1);
        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $order->game_id)
                ->where('type', GameEventType::GameCancelled)
                ->count(),
        );
    }
}
