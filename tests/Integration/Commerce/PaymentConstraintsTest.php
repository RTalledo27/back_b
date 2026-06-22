<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentConstraintsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupOrder(): Order
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'pay-test-'.fake()->unique()->lexify('?????'),
            'name' => 'P',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ]);

        return Order::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'subtotal_cents' => 100,
            'total_cents' => 100,
            'currency' => 'PEN',
            'status' => OrderStatus::Pending,
        ]);
    }

    public function test_one_payment_per_order(): void
    {
        $order = $this->setupOrder();

        Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 100,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);

        $this->expectException(QueryException::class);

        Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 100,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);
    }

    public function test_method_other_than_manual_rejected_by_db(): void
    {
        // Bypass the enum cast on the model and hit the CHECK constraint directly.
        $order = $this->setupOrder();

        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'id' => (string) Str::uuid7(),
            'order_id' => $order->id,
            'amount_cents' => 100,
            'currency' => 'PEN',
            'method' => 'mercado_pago',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_negative_amount_rejected(): void
    {
        $order = $this->setupOrder();

        $this->expectException(QueryException::class);

        Payment::create([
            'order_id' => $order->id,
            'amount_cents' => -1,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);
    }
}
