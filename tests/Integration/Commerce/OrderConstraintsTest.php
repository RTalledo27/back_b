<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Exceptions\InvalidOrderTransition;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderConstraintsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function basePayload(): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'order-test',
            'name' => 'OT',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ]);

        return [
            'user_id' => $user->id,
            'game_id' => $game->id,
            'subtotal_cents' => 500,
            'total_cents' => 500,
            'currency' => 'PEN',
        ];
    }

    public function test_creates_order_with_defaults(): void
    {
        $order = Order::create($this->basePayload());

        $this->assertNotNull($order->id);
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    public function test_rejects_invalid_status_via_db_check(): void
    {
        // Bypass the Eloquent enum cast (which would throw ValueError before
        // hitting the DB) to exercise the PostgreSQL CHECK constraint directly.
        $payload = $this->basePayload();

        $this->expectException(QueryException::class);

        DB::table('orders')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $payload['user_id'],
            'game_id' => $payload['game_id'],
            'status' => 'bogus',
            'subtotal_cents' => 100,
            'total_cents' => 100,
            'currency' => 'PEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_rejects_negative_totals(): void
    {
        $this->expectException(QueryException::class);

        Order::create([...$this->basePayload(), 'total_cents' => -1]);
    }

    public function test_rejects_lowercase_currency(): void
    {
        $this->expectException(QueryException::class);

        Order::create([...$this->basePayload(), 'currency' => 'pen']);
    }

    public function test_transition_to_advances_status(): void
    {
        $order = Order::create($this->basePayload());

        $order->transitionTo(OrderStatus::PaymentSubmitted);

        $this->assertSame(OrderStatus::PaymentSubmitted, $order->status);
    }

    public function test_transition_to_rejects_invalid_jump(): void
    {
        $order = Order::create($this->basePayload());

        $this->expectException(InvalidOrderTransition::class);

        $order->transitionTo(OrderStatus::Paid);
    }
}
