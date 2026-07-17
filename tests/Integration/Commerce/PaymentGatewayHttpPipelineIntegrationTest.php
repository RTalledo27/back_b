<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\Gateway\FakePaymentGatewayWebhookVerifier;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PaymentGatewayHttpPipelineIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payment_gateways.http_enabled' => true,
            'payment_gateways.credentials.webhook_secret' => 'test-secret',
        ]);
        Notification::fake();
    }

    #[Test]
    public function http_attempt_and_signed_webhook_complete_the_existing_commercial_pipeline(): void
    {
        [$user, $order, $payment, $gameNumber] = $this->makeFixture();
        Sanctum::actingAs($user);

        $attemptResponse = $this->postJson(
            '/api/v1/me/orders/'.$order->id.'/gateway-attempts',
            ['provider' => 'fake', 'amount_cents' => 1, 'currency' => 'USD'],
            ['Idempotency-Key' => 'integration-gateway-attempt-key'],
        )->assertCreated();
        $attempt = PaymentGatewayAttempt::query()->findOrFail($attemptResponse->json('data.id'));

        $payload = [
            'provider_event_id' => 'evt-http-pipeline',
            'event_type' => 'payment.status_changed',
            'status' => 'paid',
            'amount_cents' => 500,
            'currency' => 'PEN',
            'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'provider_attempt_id' => $attempt->provider_attempt_id,
            'provider_transaction_id' => 'fake-http-pipeline-transaction',
            'environment' => 'sandbox',
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = CarbonImmutable::now('UTC')->startOfSecond();
        $signature = (new FakePaymentGatewayWebhookVerifier)->sign($raw, 'test-secret', $timestamp->timestamp);

        $webhookResponse = $this->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GATEWAY_EVENT_ID' => 'evt-http-pipeline',
            'HTTP_X_GATEWAY_TIMESTAMP' => (string) $timestamp->timestamp,
            'HTTP_X_GATEWAY_SIGNATURE' => $signature,
        ], $raw);

        $webhookResponse->assertOk()->assertExactJson(['received' => true]);
        $this->assertSame('approved', $payment->refresh()->status->value);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame('sold', $gameNumber->refresh()->status->value);
        $this->assertSame(1, GameEntry::query()->where('game_id', $order->game_id)->count());
        $this->assertSame(1, DB::table('game_events')->where('type', GameEventType::PaymentApproved->value)->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'payment_approved')->count());
        $this->assertSame(1, DB::table('payment_gateway_transactions')->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function http_webhook_replay_does_not_duplicate_the_transaction_settlement_or_outbox(): void
    {
        [$user, $order] = $this->makeFixture();
        Sanctum::actingAs($user);
        $attemptResponse = $this->postJson(
            '/api/v1/me/orders/'.$order->id.'/gateway-attempts',
            ['provider' => 'fake'],
            ['Idempotency-Key' => 'integration-gateway-replay-key'],
        )->assertCreated();
        $attempt = PaymentGatewayAttempt::query()->findOrFail($attemptResponse->json('data.id'));
        $payload = [
            'provider_event_id' => 'evt-http-replay',
            'event_type' => 'payment.status_changed',
            'status' => 'paid',
            'amount_cents' => 500,
            'currency' => 'PEN',
            'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'provider_attempt_id' => $attempt->provider_attempt_id,
            'provider_transaction_id' => 'fake-http-replay-transaction',
            'environment' => 'sandbox',
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = CarbonImmutable::now('UTC')->startOfSecond();
        $signature = (new FakePaymentGatewayWebhookVerifier)->sign($raw, 'test-secret', $timestamp->timestamp);
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GATEWAY_EVENT_ID' => 'evt-http-replay',
            'HTTP_X_GATEWAY_TIMESTAMP' => (string) $timestamp->timestamp,
            'HTTP_X_GATEWAY_SIGNATURE' => $signature,
        ];

        $this->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], $headers, $raw)->assertOk();
        $this->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], $headers, $raw)
            ->assertOk()
            ->assertExactJson(['received' => true]);

        $this->assertSame(1, DB::table('payment_gateway_webhooks')->count());
        $this->assertSame(1, DB::table('payment_gateway_transactions')->count());
        $this->assertSame(1, DB::table('game_entries')->where('game_id', $order->game_id)->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'payment_approved')->count());
    }

    /**
     * @return array{User, Order, Payment, GameNumber}
     */
    private function makeFixture(): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'pipeline-'.fake()->unique()->lexify('?????'),
            'name' => 'Gateway HTTP pipeline game',
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
        $gameNumber = GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => GameNumberStatus::Reserved,
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500,
            'total_cents' => 500,
            'currency' => 'PEN',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'game_number_id' => $gameNumber->id,
            'unit_price_cents' => 500,
        ]);
        NumberReservation::create([
            'order_id' => $order->id,
            'game_number_id' => $gameNumber->id,
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'status' => 'pending',
            'method' => 'manual',
        ]);

        return [$user, $order, $payment, $gameNumber];
    }
}
