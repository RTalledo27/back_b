<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayAttemptData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayTransactionData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayWebhookData;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayAttemptAction;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayTransactionAction;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayWebhookAction;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PaymentGatewayLedgerTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function it_creates_the_three_ledger_tables_with_the_required_unique_keys(): void
    {
        $this->assertTrue(Schema::hasTable('payment_gateway_attempts'));
        $this->assertTrue(Schema::hasTable('payment_gateway_transactions'));
        $this->assertTrue(Schema::hasTable('payment_gateway_webhooks'));
        $this->assertFalse(Schema::hasColumn('payment_gateway_webhooks', 'payload'));
        $this->assertFalse(Schema::hasColumn('payment_gateway_transactions', 'card_number'));
        $this->assertFalse(Schema::hasColumn('payment_gateway_transactions', 'cvv'));
    }

    #[Test]
    public function it_replays_an_attempt_without_creating_a_second_row(): void
    {
        [$order, $payment] = $this->makeOrderAndPayment();
        $data = $this->attemptData($order->id, $payment->id);
        $action = new RecordPaymentGatewayAttemptAction;

        $first = $action->execute($data);
        $replay = $action->execute($data);

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('payment_gateway_attempts', 1);
        $this->assertSame('7', $first->id[14]);
    }

    #[Test]
    public function it_rejects_an_attempt_replay_with_a_different_fingerprint(): void
    {
        [$order, $payment] = $this->makeOrderAndPayment();
        $action = new RecordPaymentGatewayAttemptAction;
        $action->execute($this->attemptData($order->id, $payment->id));

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(1002);

        $action->execute($this->attemptData($order->id, $payment->id, 'different-fingerprint'));
    }

    #[Test]
    public function it_replays_a_transaction_and_rejects_conflicting_immutable_data(): void
    {
        [$order, $payment] = $this->makeOrderAndPayment();
        $attempt = (new RecordPaymentGatewayAttemptAction)->execute(
            $this->attemptData($order->id, $payment->id),
        );
        $action = new RecordPaymentGatewayTransactionAction;
        $data = $this->transactionData($attempt->id, $payment->id);

        $first = $action->execute($data);
        $replay = $action->execute($data);

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('payment_gateway_transactions', 1);

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(1002);
        $action->execute($this->transactionData($attempt->id, $payment->id, amountCents: 501));
    }

    #[Test]
    public function it_replays_a_webhook_and_rejects_a_changed_payload_hash(): void
    {
        $action = new RecordPaymentGatewayWebhookAction;
        $data = new PaymentGatewayWebhookData('fake', 'evt-1', 'payment.paid', true, hash('sha256', 'payload'));

        $first = $action->execute($data);
        $replay = $action->execute($data);

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('payment_gateway_webhooks', 1);

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(1002);
        $action->execute(new PaymentGatewayWebhookData('fake', 'evt-1', 'payment.paid', true, hash('sha256', 'changed')));
    }

    #[Test]
    public function it_does_not_change_commerce_state_when_recording_gateway_rows(): void
    {
        [$order, $payment] = $this->makeOrderAndPayment();
        $orderStatus = $order->status->value;
        $paymentStatus = $payment->status->value;
        $attempt = (new RecordPaymentGatewayAttemptAction)->execute(
            $this->attemptData($order->id, $payment->id),
        );

        (new RecordPaymentGatewayTransactionAction)->execute(
            $this->transactionData($attempt->id, $payment->id),
        );
        (new RecordPaymentGatewayWebhookAction)->execute(
            new PaymentGatewayWebhookData('fake', 'evt-state', 'payment.paid', true, hash('sha256', 'state')),
        );

        $this->assertSame($orderStatus, $order->refresh()->status->value);
        $this->assertSame($paymentStatus, $payment->refresh()->status->value);
    }

    #[Test]
    public function postgres_constraints_reject_invalid_gateway_amounts(): void
    {
        [$order, $payment] = $this->makeOrderAndPayment();

        $this->expectException(QueryException::class);
        $this->getConnection()->table('payment_gateway_attempts')->insert([
            'id' => (string) Str::uuid7(),
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'provider' => 'fake',
            'environment' => 'sandbox',
            'idempotency_key_hash' => hash('sha256', 'constraint'),
            'request_fingerprint' => hash('sha256', 'constraint-fingerprint'),
            'status' => 'pending',
            'amount_cents' => 0,
            'currency' => 'PEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attemptData(string $orderId, string $paymentId, string $fingerprint = 'fingerprint'): PaymentGatewayAttemptData
    {
        return new PaymentGatewayAttemptData(
            orderId: $orderId,
            paymentId: $paymentId,
            amountCents: 500,
            currency: 'pen',
            provider: 'fake',
            environment: 'sandbox',
            idempotencyKeyHash: hash('sha256', 'attempt-key'),
            requestFingerprint: hash('sha256', $fingerprint),
        );
    }

    private function transactionData(string $attemptId, string $paymentId, int $amountCents = 500): PaymentGatewayTransactionData
    {
        return new PaymentGatewayTransactionData(
            paymentGatewayAttemptId: $attemptId,
            paymentId: $paymentId,
            provider: 'fake',
            providerTransactionId: 'txn-1',
            status: 'paid',
            amountCents: $amountCents,
            currency: 'PEN',
            rawReferenceHash: hash('sha256', 'reference'),
        );
    }

    private function makeOrderAndPayment(): array
    {
        $buyer = User::factory()->create();
        $game = Game::create([
            'slug' => 'ledger-'.fake()->unique()->lexify('?????'),
            'name' => 'Ledger game',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => GameStatus::SalesOpen,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id,
            'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500,
            'total_cents' => 500,
            'currency' => 'PEN',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);

        return [$order, $payment];
    }
}
