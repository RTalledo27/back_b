<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Modules\Commerce\Application\Actions\ApplyApprovedPaymentTransitionAction;
use App\Modules\Commerce\Application\Actions\ApprovePaymentAction;
use App\Modules\Commerce\Application\Gateway\Actions\ProcessGatewayWebhookAction;
use App\Modules\Commerce\Application\Gateway\Actions\SettleGatewayPaidTransactionAction;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayProvider;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayProviderRegistry;
use App\Modules\Commerce\Infrastructure\Gateway\FakePaymentGatewayProvider;
use App\Modules\Commerce\Presentation\Http\Controllers\PaymentGatewayWebhookController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\CreateGatewayPaymentAttemptController;
use App\Modules\Commerce\Presentation\Http\Controllers\Player\ShowGatewayPaymentAttemptController;
use App\Modules\Commerce\Presentation\Http\Requests\PaymentGatewayWebhookRequest;
use App\Modules\Commerce\Presentation\Http\Requests\Player\CreateGatewayPaymentAttemptRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Player\GatewayPaymentAttemptResource;
use FilesystemIterator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

final class Phase108FakeGatewayClosureTest extends TestCase
{
    private const OUTBOX_EVENT_TYPES = [
        'payment_approved',
        'payment_rejected',
        'order_refunded',
        'winner_payout_registered',
        'game_winner_declared',
    ];

    #[Test]
    public function only_the_three_approved_gateway_endpoints_exist(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());
        $gatewayRoutes = $routes->filter(
            static fn ($route): bool => str_contains($route->uri(), 'gateway-attempts')
                || str_contains($route->uri(), 'webhooks/payments'),
        )->values();

        $this->assertCount(3, $gatewayRoutes);
        $this->assertRoute('me.orders.gateway-attempts.store', ['POST'], 'gateway-attempts');
        $this->assertRoute('me.orders.gateway-attempts.show', ['GET', 'HEAD'], 'gateway-attempts');
        $this->assertRoute('webhooks.payments.store', ['POST'], 'webhooks/payments');

        foreach ($gatewayRoutes as $route) {
            $this->assertDoesNotMatchRegularExpression('/(?:checkout|refund|cancel|admin)/i', $route->uri());
        }
    }

    #[Test]
    public function the_fake_provider_is_the_only_registered_provider_and_is_in_infrastructure(): void
    {
        $registry = $this->app->make(PaymentGatewayProviderRegistry::class);

        $this->assertSame(['fake'], $registry->names());
        $this->assertSame('fake', config('payment_gateways.provider'));
        $this->assertFalse((bool) config('payment_gateways.http_enabled'));
        $this->assertInstanceOf(FakePaymentGatewayProvider::class, $registry->default());
        $this->assertInstanceOf(PaymentGatewayProvider::class, $registry->default());

        $registrySource = $this->source(PaymentGatewayProviderRegistry::class);
        $this->assertStringNotContainsString('Infrastructure\\Gateway', $registrySource);
        $this->assertStringNotContainsString('FakePaymentGatewayProvider', $registrySource);

        $reflection = new ReflectionClass(FakePaymentGatewayProvider::class);
        $this->assertStringContainsString('Infrastructure\\Gateway', $reflection->getName());
        $this->assertStringNotContainsString('Application', $reflection->getName());
    }

    #[Test]
    public function no_real_provider_sdk_or_outgoing_http_is_present(): void
    {
        $gatewaySource = implode('', $this->phpFiles(base_path('app/Modules/Commerce/Application/Gateway')))
            .implode('', $this->phpFiles(base_path('app/Modules/Commerce/Infrastructure/Gateway')));
        $composer = (string) file_get_contents(base_path('composer.json'));
        $envExample = (string) file_get_contents(base_path('.env.example'));

        $this->assertDoesNotMatchRegularExpression('/Http::|GuzzleHttp|Illuminate\\Http\\Client/i', $gatewaySource);
        $this->assertDoesNotMatchRegularExpression('/Culqi|Niubiz|Stripe|MercadoPago/i', $gatewaySource.$composer);
        $this->assertStringContainsString('PAYMENT_GATEWAY_PROVIDER=fake', $envExample);
        $this->assertStringContainsString('PAYMENT_GATEWAY_ENV=sandbox', $envExample);
        $this->assertStringContainsString('PAYMENT_GATEWAY_HTTP_ENABLED=false', $envExample);
        $this->assertDoesNotMatchRegularExpression('/(?:sk_live_|pk_live_|CULQI|NIUBIZ|STRIPE|MERCADOPAGO)/i', $envExample);
    }

    #[Test]
    public function gateway_http_uses_auth_ownership_verification_and_feature_flag_guards(): void
    {
        $create = Route::getRoutes()->getByName('me.orders.gateway-attempts.store');
        $show = Route::getRoutes()->getByName('me.orders.gateway-attempts.show');
        $webhook = Route::getRoutes()->getByName('webhooks.payments.store');

        $this->assertNotNull($create);
        $this->assertNotNull($show);
        $this->assertNotNull($webhook);
        $this->assertContains('auth:sanctum', $create->gatherMiddleware());
        $this->assertContains('verified', $create->gatherMiddleware());
        $this->assertContains('auth:sanctum', $show->gatherMiddleware());
        $this->assertNotContains('auth:sanctum', $webhook->gatherMiddleware());
        $this->assertContains('payment-gateway.http', $create->gatherMiddleware());
        $this->assertContains('payment-gateway.http', $show->gatherMiddleware());
        $this->assertContains('payment-gateway.http', $webhook->gatherMiddleware());

        $policy = (string) file_get_contents(base_path('app/Modules/Commerce/Presentation/Http/Policies/OrderPolicy.php'));
        $this->assertStringContainsString('createGatewayAttempt', $policy);
        $this->assertStringContainsString('viewGatewayAttempt', $policy);
        $this->assertStringContainsString('$order->user_id === $user->id', $policy);
    }

    #[Test]
    public function controllers_are_thin_and_requests_and_resources_are_separate(): void
    {
        foreach ([
            CreateGatewayPaymentAttemptController::class,
            ShowGatewayPaymentAttemptController::class,
            PaymentGatewayWebhookController::class,
        ] as $controllerClass) {
            $reflection = new ReflectionClass($controllerClass);
            $method = $reflection->getMethod('__invoke');
            $source = (string) file_get_contents($reflection->getFileName());

            $this->assertTrue($method->isPublic());
            $this->assertStringNotContainsString('DB::', $source);
            $this->assertStringNotContainsString('Http::', $source);
            $this->assertStringNotContainsString('Notification::', $source);
            $this->assertStringNotContainsString('Mail::', $source);
            $this->assertStringNotContainsString('->save(', $source);
            $this->assertStringNotContainsString('::create(', $source);
        }

        $this->assertTrue(is_a(CreateGatewayPaymentAttemptRequest::class, FormRequest::class, true));
        $this->assertTrue(is_a(PaymentGatewayWebhookRequest::class, FormRequest::class, true));
        $this->assertTrue(is_a(GatewayPaymentAttemptResource::class, JsonResource::class, true));
        $this->assertTrue((new ReflectionClass(GatewayPaymentAttemptResource::class))->hasMethod('toArray'));
    }

    #[Test]
    public function gateway_ledger_has_no_raw_payload_or_sensitive_persisted_fields(): void
    {
        $paths = glob(database_path('migrations/*payment_gateway_*.php')) ?: [];
        $forbiddenColumns = '/->(?:string|text|json|jsonb|binary|longText)\(\s*[\'\"](?:payload|raw_payload|card|card_number|cvv|token|secret|secret_key|signature)\s*[\'\"]\s*\)/i';

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression($forbiddenColumns, $source, $path);
            $this->assertStringNotContainsString('gen_random_uuid', strtolower($source));
        }

        foreach ([
            base_path('app/Modules/Commerce/Infrastructure/Gateway/Models/PaymentGatewayAttempt.php'),
            base_path('app/Modules/Commerce/Infrastructure/Gateway/Models/PaymentGatewayTransaction.php'),
            base_path('app/Modules/Commerce/Infrastructure/Gateway/Models/PaymentGatewayWebhook.php'),
        ] as $path) {
            $source = (string) file_get_contents($path);

            $this->assertDoesNotMatchRegularExpression('/[\'\"](?:payload|raw_payload|card|card_number|cvv|token|secret|secret_key|signature)[\'\"]\s*=>/i', $source, $path);
        }
    }

    #[Test]
    public function gateway_does_not_log_raw_bodies_signatures_secrets_or_tokens(): void
    {
        $source = implode('', $this->phpFiles(base_path('app/Modules/Commerce/Application/Gateway')))
            .implode('', $this->phpFiles(base_path('app/Modules/Commerce/Infrastructure/Gateway')));

        $this->assertDoesNotMatchRegularExpression('/\b(?:Log|logger)\s*(?:::|\()/', $source);
        $this->assertDoesNotMatchRegularExpression('/(?:rawPayload|signature|secret|token|payload).*(?:Log|logger)/is', $source);
    }

    #[Test]
    public function gateway_actions_do_not_send_notifications_or_reject_payments(): void
    {
        $source = implode('', $this->phpFiles(base_path('app/Modules/Commerce/Application/Gateway')));

        $this->assertStringNotContainsString('notify(', $source);
        $this->assertStringNotContainsString('Mail::', $source);
        $this->assertStringNotContainsString('Notification::', $source);
        $this->assertStringNotContainsString('RejectPaymentAction', $source);
        $this->assertStringNotContainsString('RecordOutboxEventAction', $source);
    }

    #[Test]
    public function only_paid_and_captured_states_enter_settlement(): void
    {
        $source = $this->source(ProcessGatewayWebhookAction::class);

        $this->assertStringContainsString('PaymentGatewayTransactionStatus::Paid', $source);
        $this->assertStringContainsString('PaymentGatewayTransactionStatus::Captured', $source);
        $this->assertStringContainsString('SettleGatewayPaidTransactionAction', $source);
        $this->assertStringContainsString('GatewayPaymentSettlementRequest', $source);
        $this->assertStringNotContainsString('RejectPaymentAction', $source);
        $this->assertStringNotContainsString('release', strtolower($source));
    }

    #[Test]
    public function manual_and_gateway_approval_use_the_shared_transition_without_a_fake_reviewer(): void
    {
        $manualConstructor = (new ReflectionClass(ApprovePaymentAction::class))->getConstructor();
        $gatewayConstructor = (new ReflectionClass(SettleGatewayPaidTransactionAction::class))->getConstructor();

        $this->assertNotNull($manualConstructor);
        $this->assertNotNull($gatewayConstructor);
        $this->assertSame(ApplyApprovedPaymentTransitionAction::class, (string) $manualConstructor->getParameters()[0]->getType());
        $this->assertSame(ApplyApprovedPaymentTransitionAction::class, (string) $gatewayConstructor->getParameters()[0]->getType());

        $manualSource = $this->source(ApprovePaymentAction::class);
        $gatewaySource = $this->source(SettleGatewayPaidTransactionAction::class);
        $this->assertStringContainsString("origin: 'manual'", $manualSource);
        $this->assertStringContainsString("origin: 'gateway'", $gatewaySource);
        $this->assertStringNotContainsString('reviewerUserId:', $gatewaySource);
    }

    #[Test]
    public function outbox_and_notifications_remain_unchanged(): void
    {
        $dispatcher = $this->sourceByPath(base_path('app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php'));
        $handlers = array_map(
            static fn (string $path): string => basename($path, '.php'),
            glob(base_path('app/Modules/Shared/Infrastructure/Outbox/Handlers/*NotificationHandler.php')) ?: [],
        );

        $this->assertEqualsCanonicalizing([
            'GameWinnerDeclaredNotificationHandler',
            'OrderRefundedNotificationHandler',
            'PaymentApprovedNotificationHandler',
            'PaymentRejectedNotificationHandler',
            'WinnerPayoutRegisteredNotificationHandler',
        ], $handlers);

        foreach (self::OUTBOX_EVENT_TYPES as $eventType) {
            $this->assertStringContainsString("'{$eventType}'", $dispatcher);
        }

        $this->assertDoesNotMatchRegularExpression('/gateway|webhook|whatsapp|sms/i', $dispatcher);
    }

    #[Test]
    public function phase_documentation_declares_the_final_scope_and_real_guarantees(): void
    {
        $documentation = (string) file_get_contents(base_path('docs/phase-10.md'));

        foreach ([
            'Fase 10.8',
            'PAYMENT_GATEWAY_HTTP_ENABLED=false',
            'payment_gateway_attempts',
            'payment_gateway_transactions',
            'payment_gateway_webhooks',
            'applied_at',
            'at-least-once',
            'exactly-once',
            'Mailpit',
            'worker',
            'failed',
            'expired',
            'proveedor real',
            'smoke test',
        ] as $term) {
            $this->assertStringContainsString($term, $documentation);
        }

        $this->assertStringContainsString('No se implementa', $documentation);
        $this->assertStringContainsString('No se afirma', $documentation);
    }

    private function assertRoute(string $name, array $methods, string $uriPart): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, $name);
        $this->assertSame($methods, $route->methods(), $name);
        $this->assertStringContainsString($uriPart, $route->uri(), $name);
    }

    private function source(string $class): string
    {
        return $this->sourceByPath((new ReflectionClass($class))->getFileName());
    }

    private function sourceByPath(string $path): string
    {
        $source = file_get_contents($path);

        $this->assertIsString($source, $path);

        return $source;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        $files = [];

        if (! is_dir($directory)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
