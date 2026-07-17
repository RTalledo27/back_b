<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Modules\Commerce\Application\Gateway\Actions\CreateGatewayPaymentAttemptAction;
use App\Modules\Commerce\Application\Gateway\Actions\ProcessGatewayWebhookAction;
use App\Modules\Commerce\Application\Gateway\Actions\RecordGatewayWebhookNotificationAction;
use App\Modules\Commerce\Presentation\Http\Controllers\PaymentGatewayWebhookController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\CreateGatewayPaymentAttemptController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\ShowGatewayPaymentAttemptController;
use App\Modules\Commerce\Presentation\Http\Resources\Player\GatewayPaymentAttemptResource;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

final class Phase107GatewayHttpBoundaryTest extends TestCase
{
    #[Test]
    public function gateway_http_routes_have_the_expected_public_boundary(): void
    {
        $create = Route::getRoutes()->getByName('me.orders.gateway-attempts.store');
        $show = Route::getRoutes()->getByName('me.orders.gateway-attempts.show');
        $webhook = Route::getRoutes()->getByName('webhooks.payments.store');

        $this->assertNotNull($create);
        $this->assertNotNull($show);
        $this->assertNotNull($webhook);
        $this->assertSame(['POST'], $create->methods());
        $this->assertSame(['GET', 'HEAD'], $show->methods());
        $this->assertSame(['POST'], $webhook->methods());
        $createMiddleware = $create->gatherMiddleware();
        $showMiddleware = $show->gatherMiddleware();
        $webhookMiddleware = $webhook->gatherMiddleware();

        $this->assertContains('auth:sanctum', $createMiddleware);
        $this->assertContains('verified', $createMiddleware);
        $this->assertContains('auth:sanctum', $showMiddleware);
        $this->assertNotContains('auth:sanctum', $webhookMiddleware);
        $this->assertContains('payment-gateway.http', $createMiddleware);
        $this->assertContains('payment-gateway.http', $showMiddleware);
        $this->assertContains('payment-gateway.http', $webhookMiddleware);
        $this->assertSame('false', trim((string) preg_replace(
            '/^.*PAYMENT_GATEWAY_HTTP_ENABLED=(false).*$/ms',
            '$1',
            (string) file_get_contents(base_path('.env.example')),
        )));
    }

    #[Test]
    public function controllers_are_invokable_and_delegate_to_application_actions(): void
    {
        foreach ([
            CreateGatewayPaymentAttemptController::class,
            ShowGatewayPaymentAttemptController::class,
            PaymentGatewayWebhookController::class,
        ] as $controller) {
            $method = (new ReflectionClass($controller))->getMethod('__invoke');

            $this->assertTrue($method->isPublic());
        }

        $createSource = $this->source(CreateGatewayPaymentAttemptController::class);
        $webhookSource = $this->source(PaymentGatewayWebhookController::class);

        $this->assertStringContainsString('CreateGatewayPaymentAttemptAction', $createSource);
        $this->assertStringContainsString('RecordGatewayWebhookNotificationAction', $webhookSource);
        $this->assertStringContainsString('ProcessGatewayWebhookAction', $webhookSource);
        $this->assertStringNotContainsString('DB::', $createSource.$webhookSource);
        $this->assertStringNotContainsString('Http::', $createSource.$webhookSource);
        $this->assertStringNotContainsString('Notification::', $createSource.$webhookSource);
        $this->assertStringNotContainsString('Mail::', $createSource.$webhookSource);
    }

    #[Test]
    public function application_gateway_actions_control_their_transactions_without_external_http(): void
    {
        $source = implode('', [
            $this->source(CreateGatewayPaymentAttemptAction::class),
            $this->source(RecordGatewayWebhookNotificationAction::class),
            $this->source(ProcessGatewayWebhookAction::class),
        ]);

        $this->assertStringContainsString('DB::transaction', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('Notification::', $source);
        $this->assertStringNotContainsString('Mail::', $source);
        $this->assertStringNotContainsString('Stripe', $source);
        $this->assertStringNotContainsString('Culqi', $source);
        $this->assertStringNotContainsString('Niubiz', $source);
    }

    #[Test]
    public function the_public_resource_has_no_query_or_internal_gateway_metadata(): void
    {
        $resource = new ReflectionClass(GatewayPaymentAttemptResource::class);

        $this->assertTrue($resource->hasMethod('toArray'));
        $this->assertFalse($resource->hasMethod('query'));

        $source = $this->source(GatewayPaymentAttemptResource::class);
        foreach (['PaymentGatewayWebhook', 'idempotency_key', 'payload', 'signature', 'metadata', 'user_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($source));
        }
    }

    #[Test]
    public function this_boundary_does_not_add_notification_routes_or_real_provider_integrations(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => strtolower($route->methods()[0].' '.$route->uri()))
            ->implode("\n");

        $this->assertStringNotContainsString('stripe', $routes);
        $this->assertStringNotContainsString('culqi', $routes);
        $this->assertStringNotContainsString('niubiz', $routes);
        $this->assertSame([], glob(app_path('Modules/Commerce/Infrastructure/Gateway/*Stripe*')) ?: []);
        $this->assertSame([], glob(app_path('Modules/Commerce/Infrastructure/Gateway/*Culqi*')) ?: []);
        $this->assertSame([], glob(app_path('Modules/Commerce/Infrastructure/Gateway/*Niubiz*')) ?: []);
    }

    #[Test]
    public function phase_documentation_describes_the_safe_http_contract_and_its_limits(): void
    {
        $documentation = file_get_contents(base_path('docs/phase-10.md'));

        $this->assertIsString($documentation);
        foreach ([
            'Fase 10.7',
            'PAYMENT_GATEWAY_HTTP_ENABLED=false',
            'X-Gateway-Event-Id',
            'X-Gateway-Timestamp',
            'X-Gateway-Signature',
            'Idempotency-Key',
            'mejor esfuerzo',
            'exactly-once',
            'No se implementan WhatsApp',
        ] as $term) {
            $this->assertStringContainsString($term, $documentation);
        }
    }

    private function source(string $class): string
    {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        $this->assertIsString($source);

        return $source;
    }
}
