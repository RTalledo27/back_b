<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\Refund;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RefundConstraintsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeOrderAndPayment(): array
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();

        $game = Game::create([
            'slug' => 'rc-'.fake()->unique()->lexify('?????'),
            'name' => 'RC',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::SalesOpen,
        ]);

        $order = Order::create([
            'user_id' => $buyer->id,
            'game_id' => $game->id,
            'status' => OrderStatus::Paid,
            'subtotal_cents' => 500,
            'total_cents' => 500,
            'currency' => 'PEN',
            'expires_at' => null,
            'paid_at' => now(),
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Approved,
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
        ]);

        return [$order, $payment, $admin];
    }

    private function validRefundRow(string $orderId, string $paymentId, int $userId, ?string $keyHash = null): array
    {
        $keyHash ??= hash('sha256', 'test-key-'.fake()->uuid());

        return [
            'id' => (string) Str::uuid7(),
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'reason' => 'Test refund reason for constraint tests.',
            'idempotency_key_hash' => $keyHash,
            'request_fingerprint' => hash('sha256', 'fingerprint-'.fake()->uuid()),
            'processed_by_user_id' => $userId,
            'processed_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ];
    }

    public function test_inserts_valid_refund_row(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();

        DB::table('refunds')->insert($this->validRefundRow($order->id, $payment->id, $admin->id));

        $this->assertSame(1, DB::table('refunds')->where('order_id', $order->id)->count());
    }

    public function test_unique_order_id_rejects_duplicate_refund(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();

        $row = $this->validRefundRow($order->id, $payment->id, $admin->id);
        DB::table('refunds')->insert($row);

        $this->expectException(QueryException::class);

        DB::table('refunds')->insert([
            ...$this->validRefundRow($order->id, $payment->id, $admin->id),
        ]);
    }

    public function test_unique_idempotency_key_hash_rejects_duplicate_key(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();
        [$order2, $payment2] = $this->makeOrderAndPayment();

        $keyHash = hash('sha256', 'shared-key');
        DB::table('refunds')->insert($this->validRefundRow($order->id, $payment->id, $admin->id, $keyHash));

        $this->expectException(QueryException::class);

        DB::table('refunds')->insert($this->validRefundRow($order2->id, $payment2->id, $admin->id, $keyHash));
    }

    public function test_amount_check_rejects_zero(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();

        $this->expectException(QueryException::class);

        DB::table('refunds')->insert([
            ...$this->validRefundRow($order->id, $payment->id, $admin->id),
            'amount_cents' => 0,
        ]);
    }

    public function test_amount_check_rejects_negative(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();

        $this->expectException(QueryException::class);

        DB::table('refunds')->insert([
            ...$this->validRefundRow($order->id, $payment->id, $admin->id),
            'amount_cents' => -1,
        ]);
    }

    public function test_currency_check_rejects_lowercase(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();

        $this->expectException(QueryException::class);

        DB::table('refunds')->insert([
            ...$this->validRefundRow($order->id, $payment->id, $admin->id),
            'currency' => 'pen',
        ]);
    }

    public function test_key_hash_check_rejects_non_hex_string(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();

        $this->expectException(QueryException::class);

        DB::table('refunds')->insert([
            ...$this->validRefundRow($order->id, $payment->id, $admin->id),
            'idempotency_key_hash' => str_repeat('z', 64),
        ]);
    }

    public function test_fingerprint_check_rejects_non_hex_string(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();

        $this->expectException(QueryException::class);

        DB::table('refunds')->insert([
            ...$this->validRefundRow($order->id, $payment->id, $admin->id),
            'request_fingerprint' => str_repeat('z', 64),
        ]);
    }

    public function test_reason_check_rejects_blank_string(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();

        $this->expectException(QueryException::class);

        DB::table('refunds')->insert([
            ...$this->validRefundRow($order->id, $payment->id, $admin->id),
            'reason' => '   ',
        ]);
    }

    public function test_refund_model_is_append_only_update_throws(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();
        DB::table('refunds')->insert($this->validRefundRow($order->id, $payment->id, $admin->id));

        $refund = Refund::query()
            ->where('order_id', $order->id)
            ->firstOrFail();

        $this->expectException(ImmutableModelException::class);

        $refund->forceFill(['reason' => 'changed'])->save();
    }

    public function test_refund_model_is_append_only_delete_throws(): void
    {
        [$order, $payment, $admin] = $this->makeOrderAndPayment();
        DB::table('refunds')->insert($this->validRefundRow($order->id, $payment->id, $admin->id));

        $refund = Refund::query()
            ->where('order_id', $order->id)
            ->firstOrFail();

        $this->expectException(ImmutableModelException::class);

        $refund->delete();
    }
}
