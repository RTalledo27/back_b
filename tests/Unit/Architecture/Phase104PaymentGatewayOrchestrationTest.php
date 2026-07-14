<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Modules\Commerce\Application\Gateway\Actions\ConfirmGatewayPaymentAttemptAction;
use App\Modules\Commerce\Application\Gateway\Actions\CreateGatewayPaymentAttemptAction;
use App\Modules\Commerce\Application\Gateway\Actions\RecordGatewayWebhookNotificationAction;
use App\Modules\Commerce\Infrastructure\Gateway\FakePaymentGatewayProvider;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

final class Phase104PaymentGatewayOrchestrationTest extends TestCase
{
    #[Test]
    public function orchestration_actions_exist_and_are_internal(): void
    {
        foreach ([
            CreateGatewayPaymentAttemptAction::class,
            ConfirmGatewayPaymentAttemptAction::class,
            RecordGatewayWebhookNotificationAction::class,
        ] as $actionClass) {
            $this->assertTrue(class_exists($actionClass));
            $this->assertStringContainsString('Application\\Gateway\\Actions', $actionClass);
        }

        $fakeReflection = new ReflectionClass(FakePaymentGatewayProvider::class);
        $this->assertStringContainsString('Infrastructure\\Gateway', $fakeReflection->getName());
        $this->assertStringNotContainsString('Domain', $fakeReflection->getFileName());
    }

    #[Test]
    public function orchestration_actions_have_no_external_provider_or_notification_side_effects(): void
    {
        foreach ([
            CreateGatewayPaymentAttemptAction::class,
            ConfirmGatewayPaymentAttemptAction::class,
            RecordGatewayWebhookNotificationAction::class,
        ] as $actionClass) {
            $source = file_get_contents((new ReflectionClass($actionClass))->getFileName());

            $this->assertIsString($source);
            $this->assertStringNotContainsString('Http::', $source);
            $this->assertStringNotContainsString('Culqi', $source);
            $this->assertStringNotContainsString('Niubiz', $source);
            $this->assertStringNotContainsString('Stripe', $source);
            $this->assertStringNotContainsString('ApprovePaymentAction', $source);
            $this->assertStringNotContainsString('RecordOutboxEventAction', $source);
            $this->assertStringNotContainsString('notify(', $source);
            $this->assertStringNotContainsString('Mail::', $source);
            $this->assertStringNotContainsString('Notification::', $source);
        }
    }

    #[Test]
    public function no_public_checkout_or_webhook_route_exists(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->methods()[0].' '.$route->uri())
            ->implode("\n");

        $this->assertDoesNotMatchRegularExpression('/checkout|webhooks?\/payments/i', $routes);
    }

    #[Test]
    public function fake_configuration_has_no_real_credentials_or_new_outbox_types(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));
        $dispatcher = file_get_contents(base_path('app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php'));
        $documentation = file_get_contents(base_path('docs/phase-10.md'));

        $this->assertIsString($envExample);
        $this->assertIsString($dispatcher);
        $this->assertIsString($documentation);
        $this->assertStringContainsString('PAYMENT_GATEWAY_PROVIDER=fake', $envExample);
        $this->assertStringContainsString('PAYMENT_GATEWAY_ENV=sandbox', $envExample);
        $this->assertDoesNotMatchRegularExpression('/sk_live_|pk_live_|culqi|niubiz|stripe/i', $envExample);
        $this->assertDoesNotMatchRegularExpression('/gateway|webhook/i', $dispatcher);
        $this->assertStringContainsString('Fase 10.4', $documentation);
        $this->assertStringContainsString('Fase 10.5', $documentation);
    }
}
