<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Modules\Commerce\Application\Gateway\Actions\ProcessGatewayWebhookAction;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayTransactionStatus;
use App\Modules\Shared\Infrastructure\Outbox\OutboxEventDispatcher;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

final class Phase106GatewayWebhookProcessingTest extends TestCase
{
    #[Test]
    public function the_processing_action_is_durable_and_has_no_external_delivery_side_effects(): void
    {
        $source = file_get_contents((new ReflectionClass(ProcessGatewayWebhookAction::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringContainsString('ProcessGatewayWebhookData', $source);
        $this->assertStringContainsString('RecordPaymentGatewayTransactionAction', $source);
        $this->assertStringContainsString('SettleGatewayPaidTransactionAction', $source);
        $this->assertStringContainsString('lockForUpdate', $source);
        $this->assertStringContainsString('processed_at', $source);
        $this->assertStringContainsString('failed_at', $source);
        $this->assertStringContainsString('processing_attempts', $source);
        $this->assertStringNotContainsString('RejectPaymentAction', $source);
        $this->assertStringNotContainsString('Notification::', $source);
        $this->assertStringNotContainsString('Mail::', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('Culqi', $source);
        $this->assertStringNotContainsString('Niubiz', $source);
        $this->assertStringNotContainsString('Stripe', $source);
    }

    #[Test]
    public function only_paid_and_captured_statuses_can_enter_commercial_settlement(): void
    {
        $source = file_get_contents((new ReflectionClass(ProcessGatewayWebhookAction::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringContainsString('PaymentGatewayTransactionStatus::Paid', $source);
        $this->assertStringContainsString('PaymentGatewayTransactionStatus::Captured', $source);
        $this->assertStringContainsString('executeWithinTransaction', $source);
        $this->assertSame('paid', PaymentGatewayTransactionStatus::Paid->value);
        $this->assertSame('captured', PaymentGatewayTransactionStatus::Captured->value);
    }

    #[Test]
    public function the_webhook_migration_stores_safe_metadata_only(): void
    {
        $migrationPaths = glob(database_path('migrations/*add_processing_metadata_to_payment_gateway_webhooks_table.php')) ?: [];
        $this->assertCount(1, $migrationPaths);
        $source = file_get_contents($migrationPaths[0]);

        $this->assertIsString($source);
        foreach (['provider_attempt_id', 'provider_transaction_id', 'normalized_status', 'amount_cents', 'currency', 'environment', 'occurred_at', 'processing_attempts', 'last_error'] as $field) {
            $this->assertStringContainsString($field, $source);
        }
        foreach (['payload', 'card_number', 'cvv', 'token', 'credentials', 'gen_random_uuid'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($source));
        }
    }

    #[Test]
    public function the_outbox_dispatcher_keeps_exactly_the_existing_five_event_types(): void
    {
        $source = file_get_contents((new ReflectionClass(OutboxEventDispatcher::class))->getFileName());

        $this->assertIsString($source);
        foreach ([
            'payment_approved',
            'payment_rejected',
            'order_refunded',
            'winner_payout_registered',
            'game_winner_declared',
        ] as $eventType) {
            $this->assertSame(1, substr_count($source, "'{$eventType}'"));
        }
        foreach (['webhook', 'gateway', 'whatsapp', 'sms'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($source));
        }
    }

    #[Test]
    public function phase_106_did_not_add_checkout_or_external_provider_endpoints(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => strtolower($route->methods()[0].' '.$route->uri()))
            ->implode("\n");

        $this->assertStringNotContainsString('checkout', $routes);
        $this->assertStringNotContainsString('stripe', $routes);
        $this->assertStringNotContainsString('culqi', $routes);
        $this->assertStringNotContainsString('niubiz', $routes);
    }

    #[Test]
    public function phase_documentation_declares_operational_limits_and_recovery(): void
    {
        $documentation = file_get_contents(base_path('docs/phase-10.md'));

        $this->assertIsString($documentation);
        foreach (['Fase 10.6', 'ProcessGatewayWebhookAction', 'applied_at', 'processing_attempts', 'lockForUpdate', 'exactly-once', 'Fase 10.7'] as $term) {
            $this->assertStringContainsString($term, $documentation);
        }
        $this->assertStringContainsString('No crea endpoint HTTP público', $documentation);
    }
}
