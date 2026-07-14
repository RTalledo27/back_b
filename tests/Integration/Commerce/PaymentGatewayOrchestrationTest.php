<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Gateway\Actions\ConfirmGatewayPaymentAttemptAction;
use App\Modules\Commerce\Application\Gateway\Actions\CreateGatewayPaymentAttemptAction;
use App\Modules\Commerce\Application\Gateway\Actions\RecordGatewayWebhookNotificationAction;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentAttemptRequest;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentConfirmationRequest;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentNotPayableException;
use App\Modules\Commerce\Application\Gateway\GatewayWebhookRecordRequest;
use App\Modules\Commerce\Application\Gateway\GatewayWebhookSignatureException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\Gateway\FakePaymentGatewayWebhookVerifier;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PaymentGatewayOrchestrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[Test]
    public function it_creates_an_attempt_with_the_fake_provider(): void
    {
        [$user, $order, $payment] = $this->makeOrderAndPayment();

        $response = $this->createAction()->execute($this->attemptRequest($user, $order, $payment));

        $this->assertSame('fake', $response->provider);
        $this->assertSame('pending', $response->status->value);
        $this->assertSame(500, $response->amountCents);
        $this->assertSame('PEN', $response->currency);
        $this->assertStringStartsWith('fake://checkout/', (string) $response->checkoutUrl);
        $this->assertDatabaseHas('payment_gateway_attempts', [
            'id' => $response->attemptId,
            'provider' => 'fake',
            'payment_id' => $payment->id,
        ]);
    }

    #[Test]
    public function it_replays_the_same_attempt_for_the_same_key_and_fingerprint(): void
    {
        [$user, $order, $payment] = $this->makeOrderAndPayment();
        $request = $this->attemptRequest($user, $order, $payment);

        $first = $this->createAction()->execute($request);
        $replay = $this->createAction()->execute($request);

        $this->assertSame($first->attemptId, $replay->attemptId);
        $this->assertDatabaseCount('payment_gateway_attempts', 1);
    }

    #[Test]
    public function it_rejects_a_different_fingerprint_for_the_same_attempt_key(): void
    {
        [$user, $order, $payment] = $this->makeOrderAndPayment();
        $this->createAction()->execute($this->attemptRequest($user, $order, $payment));

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(1002);

        $this->createAction()->execute($this->attemptRequest(
            $user,
            $order,
            $payment,
            fingerprint: 'different-fingerprint',
        ));
    }

    #[Test]
    public function it_rejects_an_order_owned_by_another_user(): void
    {
        [$owner, $order, $payment] = $this->makeOrderAndPayment();
        $attacker = User::factory()->create();

        $this->expectException(GatewayPaymentNotPayableException::class);

        $this->createAction()->execute($this->attemptRequest($attacker, $order, $payment));
        $this->assertNotSame($owner->id, $attacker->id);
    }

    #[Test]
    public function it_rejects_a_non_payable_order_or_payment(): void
    {
        [$user, $order, $payment] = $this->makeOrderAndPayment();
        $order->status = OrderStatus::PaymentSubmitted;
        $order->save();

        $this->expectException(GatewayPaymentNotPayableException::class);

        $this->createAction()->execute($this->attemptRequest($user, $order, $payment));
    }

    #[Test]
    public function it_confirms_an_attempt_and_records_one_technical_transaction(): void
    {
        Notification::fake();
        [$user, $order, $payment] = $this->makeOrderAndPayment();
        $attempt = $this->createAction()->execute($this->attemptRequest($user, $order, $payment));
        $request = new GatewayPaymentConfirmationRequest(
            attemptId: $attempt->attemptId,
            idempotencyKeyHash: hash('sha256', 'confirm-key'),
            requestFingerprint: hash('sha256', 'confirm-fingerprint'),
        );

        $first = $this->confirmAction()->execute($request);
        $replay = $this->confirmAction()->execute($request);

        $this->assertSame('paid', $first->status->value);
        $this->assertSame($first->transactionId, $replay->transactionId);
        $this->assertDatabaseCount('payment_gateway_transactions', 1);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertDatabaseCount('outbox_events', 0);
        Notification::assertNothingSent();
    }

    #[Test]
    public function it_records_a_valid_fake_webhook_once_without_storing_the_payload(): void
    {
        Notification::fake();
        config(['payment_gateways.credentials.webhook_secret' => 'test-secret']);
        $now = CarbonImmutable::parse('2026-07-14T12:00:00Z');
        $rawPayload = json_encode([
            'provider_event_id' => 'evt-orchestration-1',
            'event_type' => 'payment.paid',
            'status' => 'paid',
            'amount_cents' => 500,
            'currency' => 'PEN',
            'occurred_at' => $now->toIso8601String(),
        ], JSON_THROW_ON_ERROR);
        $signature = (new FakePaymentGatewayWebhookVerifier)->sign(
            $rawPayload,
            'test-secret',
            $now->timestamp,
        );
        $request = new GatewayWebhookRecordRequest(
            provider: 'fake',
            rawPayload: $rawPayload,
            signature: $signature,
            now: $now,
        );

        $first = $this->webhookAction()->execute($request);
        $replay = $this->webhookAction()->execute($request);

        $this->assertSame('evt-orchestration-1', $first->providerEventId);
        $this->assertTrue($first->signatureVerified);
        $this->assertSame($first->webhookId, $replay->webhookId);
        $this->assertDatabaseCount('payment_gateway_webhooks', 1);
        $this->assertFalse(Schema::hasColumn('payment_gateway_webhooks', 'payload'));
        $this->assertDatabaseCount('outbox_events', 0);
        Notification::assertNothingSent();
    }

    #[Test]
    public function it_rejects_a_webhook_with_an_invalid_fake_signature(): void
    {
        config(['payment_gateways.credentials.webhook_secret' => 'test-secret']);

        $this->expectException(GatewayWebhookSignatureException::class);

        $this->webhookAction()->execute(new GatewayWebhookRecordRequest(
            provider: 'fake',
            rawPayload: '{"event":"payment.paid"}',
            signature: 't=1,v1='.str_repeat('0', 64),
            now: CarbonImmutable::parse('2026-07-14T12:00:00Z'),
        ));
    }

    private function createAction(): CreateGatewayPaymentAttemptAction
    {
        return app(CreateGatewayPaymentAttemptAction::class);
    }

    private function confirmAction(): ConfirmGatewayPaymentAttemptAction
    {
        return app(ConfirmGatewayPaymentAttemptAction::class);
    }

    private function webhookAction(): RecordGatewayWebhookNotificationAction
    {
        return app(RecordGatewayWebhookNotificationAction::class);
    }

    private function attemptRequest(
        User $user,
        Order $order,
        Payment $payment,
        string $key = 'attempt-key',
        string $fingerprint = 'attempt-fingerprint',
    ): GatewayPaymentAttemptRequest {
        return new GatewayPaymentAttemptRequest(
            userId: $user->id,
            orderId: $order->id,
            paymentId: $payment->id,
            provider: 'fake',
            idempotencyKeyHash: hash('sha256', $key),
            requestFingerprint: hash('sha256', $fingerprint),
        );
    }

    private function makeOrderAndPayment(): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'orchestration-'.fake()->unique()->lexify('?????'),
            'name' => 'Gateway orchestration game',
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
            'user_id' => $user->id,
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

        return [$user, $order, $payment];
    }
}
