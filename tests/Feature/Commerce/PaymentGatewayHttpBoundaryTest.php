<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\Gateway\FakePaymentGatewayWebhookVerifier;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PaymentGatewayHttpBoundaryTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payment_gateways.http_enabled' => true,
            'payment_gateways.credentials.webhook_secret' => 'test-secret',
        ]);
    }

    #[Test]
    public function the_gateway_http_surface_is_hidden_by_default(): void
    {
        config(['payment_gateways.http_enabled' => false]);
        [$user, $order] = $this->makeFixture();
        Sanctum::actingAs($user);

        $this->postJson($this->attemptUrl($order), ['provider' => 'fake'], [
            'Idempotency-Key' => 'gateway-feature-off-key',
        ])->assertNotFound();
    }

    #[Test]
    public function a_guest_cannot_create_an_attempt(): void
    {
        [, $order] = $this->makeFixture();

        $this->postJson($this->attemptUrl($order), ['provider' => 'fake'], [
            'Idempotency-Key' => 'gateway-guest-key',
        ])->assertUnauthorized();
    }

    #[Test]
    public function an_unverified_user_cannot_create_an_attempt(): void
    {
        [$owner, $order] = $this->makeFixture(user: User::factory()->unverified()->create());
        Sanctum::actingAs($owner);

        $this->postJson($this->attemptUrl($order), ['provider' => 'fake'], [
            'Idempotency-Key' => 'gateway-unverified-key',
        ])->assertForbidden()->assertJsonPath('code', 'email_not_verified');
    }

    #[Test]
    public function an_owner_can_create_and_read_a_safe_attempt_resource(): void
    {
        [$user, $order] = $this->makeFixture();
        Sanctum::actingAs($user);

        $created = $this->createAttempt($order, 'gateway-create-key');
        $attemptId = $created->json('data.id');

        $this->assertNotEmpty($attemptId);
        $this->assertSame('fake', $created->json('data.provider'));
        $this->assertSame(500, $created->json('data.amount_cents'));
        $this->assertSame('PEN', $created->json('data.currency'));
        $this->assertArrayNotHasKey('idempotency_key_hash', $created->json('data'));
        $this->assertArrayNotHasKey('request_fingerprint', $created->json('data'));
        $this->assertArrayNotHasKey('provider_transaction_id', $created->json('data'));

        $shown = $this->getJson($this->attemptUrl($order).'/'.$attemptId)->assertOk();
        $this->assertSame($attemptId, $shown->json('data.id'));
        $this->assertArrayNotHasKey('webhook_id', $shown->json('data'));
    }

    #[Test]
    public function attempt_replay_is_stable_and_a_provider_change_conflicts(): void
    {
        [$user, $order] = $this->makeFixture();
        Sanctum::actingAs($user);
        $headers = ['Idempotency-Key' => 'gateway-replay-key'];

        $first = $this->postJson($this->attemptUrl($order), ['provider' => 'fake'], $headers)->assertCreated();
        $replay = $this->postJson($this->attemptUrl($order), ['provider' => 'fake'], $headers)->assertCreated();
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));

        $this->postJson($this->attemptUrl($order), ['provider' => 'other'], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error', 'gateway_idempotency_conflict');
    }

    #[Test]
    public function another_user_cannot_enumerate_an_order_or_attempt(): void
    {
        [$owner, $order] = $this->makeFixture();
        Sanctum::actingAs($owner);
        $attempt = $this->createAttempt($order, 'gateway-owner-key')->json('data.id');

        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->postJson($this->attemptUrl($order), ['provider' => 'fake'], [
            'Idempotency-Key' => 'gateway-other-key',
        ])->assertNotFound();
        $this->getJson($this->attemptUrl($order).'/'.$attempt)->assertNotFound();
    }

    #[Test]
    public function invalid_attempt_inputs_are_rejected_without_creating_ledger_rows(): void
    {
        [$user, $order] = $this->makeFixture();
        Sanctum::actingAs($user);
        $order->status = OrderStatus::Paid;
        $order->save();

        $this->postJson($this->attemptUrl($order), ['provider' => 'fake'], [
            'Idempotency-Key' => 'gateway-not-payable',
        ])->assertStatus(422)->assertJsonPath('error', 'gateway_not_payable');

        $this->postJson($this->attemptUrl($order), ['provider' => 'unknown'], [
            'Idempotency-Key' => 'gateway-unknown-provider',
        ])->assertStatus(404);
        $this->assertDatabaseCount('payment_gateway_attempts', 0);
    }

    #[Test]
    public function webhook_feature_flag_and_provider_allowlist_are_enforced(): void
    {
        [$user, $order] = $this->makeFixture();
        Sanctum::actingAs($user);
        $attempt = PaymentGatewayAttempt::query()->first();
        $this->assertNull($attempt);

        config(['payment_gateways.http_enabled' => false]);
        $this->sendWebhook('fake', $this->payload(), validSignature: true)->assertNotFound();

        config(['payment_gateways.http_enabled' => true]);
        $this->sendWebhook('unknown', $this->payload(), validSignature: true)->assertNotFound();
    }

    #[Test]
    public function invalid_webhook_headers_signature_content_type_and_size_are_rejected(): void
    {
        $payload = json_encode($this->payload(), JSON_THROW_ON_ERROR);
        $this->call('POST', '/api/v1/webhooks/payments/fake', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GATEWAY_EVENT_ID' => 'evt-header',
            'HTTP_X_GATEWAY_TIMESTAMP' => (string) now()->timestamp,
        ], $payload)->assertStatus(401);

        $this->sendWebhook('fake', $this->payload(), validSignature: false)->assertStatus(401);
        $this->sendWebhook('fake', $this->payload(), contentType: 'text/plain')->assertStatus(415);

        config(['payment_gateways.webhook_max_body_bytes' => 10]);
        $this->sendWebhook('fake', ['too' => 'large'], validSignature: false)->assertStatus(413);
    }

    #[Test]
    public function a_valid_paid_webhook_returns_a_stable_public_acknowledgement(): void
    {
        [$user, $order] = $this->makeFixture();
        Sanctum::actingAs($user);
        $attempt = $this->createAttempt($order, 'gateway-webhook-paid')->json('data.id');
        $gatewayAttempt = PaymentGatewayAttempt::query()->findOrFail($attempt);

        $response = $this->sendWebhook('fake', $this->payload(
            providerAttemptId: $gatewayAttempt->provider_attempt_id,
            status: 'paid',
        ));

        $response->assertOk()->assertExactJson(['received' => true]);
        $this->assertDatabaseHas('payment_gateway_webhooks', [
            'provider_event_id' => 'evt-http-test',
        ]);
    }

    #[Test]
    public function webhook_replay_and_metadata_conflict_have_stable_responses(): void
    {
        [$user, $order] = $this->makeFixture();
        Sanctum::actingAs($user);
        $attemptId = $this->createAttempt($order, 'gateway-webhook-replay')->json('data.id');
        $gatewayAttempt = PaymentGatewayAttempt::query()->findOrFail($attemptId);
        $payload = $this->payload(providerAttemptId: $gatewayAttempt->provider_attempt_id);

        $this->sendWebhook('fake', $payload)->assertOk();
        $this->sendWebhook('fake', $payload)->assertOk()->assertExactJson(['received' => true]);

        $changed = $payload;
        $changed['provider_transaction_id'] = 'different-transaction';
        $this->sendWebhook('fake', $changed)->assertStatus(409);
        $this->assertDatabaseCount('payment_gateway_webhooks', 1);
    }

    #[DataProvider('nonPayableStatuses')]
    public function test_non_paid_webhook_statuses_do_not_change_commerce(string $status): void
    {
        [$user, $order, $payment] = $this->makeFixture();
        Sanctum::actingAs($user);
        $attemptId = $this->createAttempt($order, 'gateway-webhook-'.$status)->json('data.id');
        $gatewayAttempt = PaymentGatewayAttempt::query()->findOrFail($attemptId);

        $this->sendWebhook('fake', $this->payload(
            providerAttemptId: $gatewayAttempt->provider_attempt_id,
            status: $status,
        ))->assertOk();

        $this->assertSame('pending', $payment->refresh()->status->value);
        $this->assertSame('pending', $order->refresh()->status->value);
        $this->assertSame(1, DB::table('payment_gateway_transactions')->count());
        $this->assertSame(0, DB::table('outbox_events')->count());
    }

    public static function nonPayableStatuses(): array
    {
        return [
            'authorized' => ['authorized'],
            'failed' => ['failed'],
            'expired' => ['expired'],
        ];
    }

    #[Test]
    public function webhook_rate_limit_is_applied(): void
    {
        [$user, $order] = $this->makeFixture();
        Sanctum::actingAs($user);

        $last = null;
        for ($index = 0; $index <= 10; $index++) {
            $last = $this->postJson($this->attemptUrl($order), ['provider' => 'fake'], [
                'Idempotency-Key' => 'gateway-rate-key-'.$index,
            ]);
        }

        $last?->assertStatus(429)->assertJsonPath('error', 'too_many_requests');
    }

    private function createAttempt(Order $order, string $key): TestResponse
    {
        return $this->postJson($this->attemptUrl($order), ['provider' => 'fake'], [
            'Idempotency-Key' => $key,
        ])->assertCreated();
    }

    private function attemptUrl(Order $order): string
    {
        return '/api/v1/me/orders/'.$order->getKey().'/gateway-attempts';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendWebhook(
        string $provider,
        array $payload,
        bool $validSignature = true,
        string $contentType = 'application/json',
    ): TestResponse {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = CarbonImmutable::now('UTC')->startOfSecond();
        $signature = (new FakePaymentGatewayWebhookVerifier)->sign(
            $raw,
            'test-secret',
            $timestamp->timestamp,
        );

        if (! $validSignature) {
            $signature = 't='.$timestamp->timestamp.',v1='.str_repeat('0', 64);
        }

        return $this->call('POST', '/api/v1/webhooks/payments/'.$provider, [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_GATEWAY_EVENT_ID' => (string) ($payload['provider_event_id'] ?? 'evt-http-test'),
            'HTTP_X_GATEWAY_TIMESTAMP' => (string) $timestamp->timestamp,
            'HTTP_X_GATEWAY_SIGNATURE' => $signature,
        ], $raw);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $providerAttemptId = 'unresolved-attempt',
        string $status = 'paid',
    ): array {
        return [
            'provider_event_id' => 'evt-http-test',
            'event_type' => 'payment.status_changed',
            'status' => $status,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'occurred_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'provider_attempt_id' => $providerAttemptId,
            'provider_transaction_id' => 'fake-http-transaction',
            'environment' => 'sandbox',
        ];
    }

    /**
     * @return array{User, Order, Payment, GameNumber}
     */
    private function makeFixture(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $game = Game::create([
            'slug' => 'http-'.fake()->unique()->lexify('?????'),
            'name' => 'Gateway HTTP game',
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
