<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Modules\Commerce\Application\Gateway\PaymentGatewayConfirmData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayCreateAttemptData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayProvider;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayProviderRegistry;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayTransactionStatus;
use App\Modules\Commerce\Infrastructure\Gateway\FakePaymentGatewayProvider;
use App\Modules\Commerce\Infrastructure\Gateway\FakePaymentGatewayWebhookNormalizer;
use App\Modules\Commerce\Infrastructure\Gateway\FakePaymentGatewayWebhookVerifier;
use App\Modules\Commerce\Presentation\Http\Controllers\PaymentGatewayWebhookController;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PaymentGatewayFoundationTest extends TestCase
{
    public function test_fake_provider_is_the_only_resolvable_default(): void
    {
        $provider = $this->app->make(PaymentGatewayProvider::class);
        $registry = $this->app->make(PaymentGatewayProviderRegistry::class);

        self::assertInstanceOf(FakePaymentGatewayProvider::class, $provider);
        self::assertSame('fake', $provider->name());
        self::assertSame(['fake'], $registry->names());
    }

    public function test_create_attempt_is_deterministic_and_replay_safe(): void
    {
        $provider = new FakePaymentGatewayProvider;
        $data = $this->createAttemptData();

        $first = $provider->createAttempt($data);
        $replay = $provider->createAttempt($data);

        self::assertSame($first->toArray(), $replay->toArray());
        self::assertSame('fake', $first->provider);
        self::assertSame(PaymentGatewayTransactionStatus::Pending, $first->status);
        self::assertStringStartsWith('fake://', (string) $first->checkoutUrl);
    }

    public function test_create_attempt_rejects_a_different_payload_for_the_same_key(): void
    {
        $provider = new FakePaymentGatewayProvider;
        $provider->createAttempt($this->createAttemptData());

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(1002);

        $provider->createAttempt($this->createAttemptData(requestFingerprint: 'different-payload'));
    }

    public function test_create_attempt_failure_is_simulated_without_http(): void
    {
        Http::preventStrayRequests();
        $provider = new FakePaymentGatewayProvider;
        $provider->failCreateAttempt();

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(1003);

        $provider->createAttempt($this->createAttemptData());
    }

    public function test_confirm_is_replay_safe_and_can_simulate_terminal_statuses(): void
    {
        $provider = new FakePaymentGatewayProvider;
        $attempt = $provider->createAttempt($this->createAttemptData());
        $data = new PaymentGatewayConfirmData($attempt->providerAttemptId, 'confirm-key-hash', 'confirm-payload');

        $first = $provider->confirm($data);
        $replay = $provider->confirm($data);

        self::assertSame($first->toArray(), $replay->toArray());
        self::assertSame(PaymentGatewayTransactionStatus::Paid, $first->status);
        self::assertNotNull($first->capturedAt);

        $failedProvider = new FakePaymentGatewayProvider;
        $failedProvider->setConfirmStatus(PaymentGatewayTransactionStatus::Failed);
        $failedAttempt = $failedProvider->createAttempt($this->createAttemptData('failed'));
        $failed = $failedProvider->confirm(new PaymentGatewayConfirmData(
            $failedAttempt->providerAttemptId,
            'failed-confirm-key',
            'failed-confirm-payload',
        ));

        self::assertSame(PaymentGatewayTransactionStatus::Failed, $failed->status);
        self::assertNotNull($failed->failedAt);
    }

    public function test_confirm_rejects_a_different_payload_for_the_same_key(): void
    {
        $provider = new FakePaymentGatewayProvider;
        $attempt = $provider->createAttempt($this->createAttemptData('confirm-conflict'));
        $provider->confirm(new PaymentGatewayConfirmData(
            $attempt->providerAttemptId,
            'confirm-key-hash',
            'confirm-payload',
        ));

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(1002);

        $provider->confirm(new PaymentGatewayConfirmData(
            $attempt->providerAttemptId,
            'confirm-key-hash',
            'different-confirm-payload',
        ));
    }

    public function test_fake_provider_can_simulate_authorized_and_expired_statuses(): void
    {
        $authorizedProvider = new FakePaymentGatewayProvider;
        $authorizedProvider->setConfirmStatus(PaymentGatewayTransactionStatus::Authorized);
        $authorizedAttempt = $authorizedProvider->createAttempt($this->createAttemptData('authorized'));
        $authorized = $authorizedProvider->confirm(new PaymentGatewayConfirmData(
            $authorizedAttempt->providerAttemptId,
            'authorized-key',
            'authorized-payload',
        ));

        $expiredProvider = new FakePaymentGatewayProvider;
        $expiredProvider->setConfirmStatus(PaymentGatewayTransactionStatus::Expired);
        $expiredAttempt = $expiredProvider->createAttempt($this->createAttemptData('expired'));
        $expired = $expiredProvider->confirm(new PaymentGatewayConfirmData(
            $expiredAttempt->providerAttemptId,
            'expired-key',
            'expired-payload',
        ));

        self::assertSame(PaymentGatewayTransactionStatus::Authorized, $authorized->status);
        self::assertNotNull($authorized->authorizedAt);
        self::assertNull($authorized->capturedAt);
        self::assertSame(PaymentGatewayTransactionStatus::Expired, $expired->status);
        self::assertNotNull($expired->failedAt);
    }

    public function test_webhook_normalization_signature_and_duplicate_claim_are_supported(): void
    {
        $rawPayload = json_encode([
            'provider_event_id' => 'evt-1',
            'event_type' => 'payment.updated',
            'status' => PaymentGatewayTransactionStatus::Paid->value,
            'amount_cents' => 2500,
            'currency' => 'pen',
            'occurred_at' => '2026-07-13T12:00:00+00:00',
        ], JSON_THROW_ON_ERROR);
        $now = CarbonImmutable::parse('2026-07-13T12:00:10+00:00');
        $secret = 'test-only-secret';
        $verifier = new FakePaymentGatewayWebhookVerifier;
        $signature = $verifier->sign($rawPayload, $secret, $now->timestamp);
        $normalizer = new FakePaymentGatewayWebhookNormalizer;
        $payload = $normalizer->normalize('fake', $rawPayload, ['signature_verified' => 'true']);
        $provider = new FakePaymentGatewayProvider;

        self::assertTrue($verifier->verify($rawPayload, $signature, $secret, $now, 300));
        self::assertFalse($verifier->verify($rawPayload, 'invalid', $secret, $now, 300));
        self::assertFalse($verifier->verify(
            $rawPayload,
            $verifier->sign($rawPayload, $secret, $now->subMinutes(10)->timestamp),
            $secret,
            $now,
            300,
        ));
        self::assertSame('PEN', $payload->currency);
        self::assertTrue($payload->signatureVerified);
        self::assertTrue($provider->acceptWebhook($payload));
        self::assertFalse($provider->acceptWebhook($payload));
        self::assertSame(1, $provider->processedWebhookCount());
    }

    public function test_gateway_http_boundary_uses_only_the_fake_provider_route(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.payments.store');

        self::assertNotNull($route);
        self::assertSame(PaymentGatewayWebhookController::class, $route->getAction('controller'));
        self::assertContains('payment-gateway.http', $route->gatherMiddleware());
        self::assertSame(['POST'], $route->methods());

        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => strtolower($route->uri()))
            ->all();

        self::assertNotContains('api/v1/webhooks/payments/stripe', $routes);
        self::assertNotContains('api/v1/webhooks/payments/culqi', $routes);
        self::assertNotContains('api/v1/webhooks/payments/niubiz', $routes);
    }

    public function test_manual_commerce_routes_and_outbox_event_types_remain_unchanged(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->all();

        self::assertContains('api/v1/me/orders/{order}/payment-evidence', $routes);
        self::assertContains('api/v1/admin/payments/{payment}/approve', $routes);
        self::assertContains('api/v1/admin/payments/{payment}/reject', $routes);
        self::assertContains('api/v1/admin/orders/{order}/refund', $routes);

        $dispatcher = file_get_contents(
            base_path('app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php'),
        );

        self::assertNotFalse($dispatcher);
        foreach ([
            'payment_approved',
            'payment_rejected',
            'order_refunded',
            'winner_payout_registered',
            'game_winner_declared',
        ] as $eventType) {
            self::assertStringContainsString("'{$eventType}'", $dispatcher);
        }
        self::assertDoesNotMatchRegularExpression('/gateway|webhook/i', $dispatcher);
    }

    private function createAttemptData(string $suffix = 'default', string $requestFingerprint = 'payload'): PaymentGatewayCreateAttemptData
    {
        return new PaymentGatewayCreateAttemptData(
            orderId: 'order-'.$suffix,
            paymentId: 'payment-'.$suffix,
            amountCents: 2500,
            currency: 'pen',
            idempotencyKeyHash: 'idempotency-key-'.$suffix,
            requestFingerprint: $requestFingerprint,
            expiresAt: CarbonImmutable::parse('2026-07-13T13:00:00+00:00'),
        );
    }
}
