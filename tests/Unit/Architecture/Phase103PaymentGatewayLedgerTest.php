<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayAttemptAction;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayTransactionAction;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayWebhookAction;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayTransaction;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayWebhook;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

final class Phase103PaymentGatewayLedgerTest extends TestCase
{
    #[Test]
    public function the_ledger_contract_is_documented_and_has_no_public_webhook_route(): void
    {
        $documentation = file_get_contents(base_path('docs/phase-10.md'));

        $this->assertIsString($documentation);
        $this->assertStringContainsString('Fase 10.3', $documentation);
        $this->assertStringContainsString('payment_gateway_attempts', $documentation);
        $this->assertStringContainsString('payment_gateway_transactions', $documentation);
        $this->assertStringContainsString('payment_gateway_webhooks', $documentation);
        $this->assertStringContainsString('No se afirma exactly-once', $documentation);
        $this->assertStringNotContainsString('/webhooks/payments', $this->publicRoutes());
    }

    #[Test]
    public function ledger_models_are_internal_uuid_v7_models_without_payload_storage(): void
    {
        foreach ([PaymentGatewayAttempt::class, PaymentGatewayTransaction::class, PaymentGatewayWebhook::class] as $modelClass) {
            $model = new $modelClass;
            $reflection = new ReflectionClass($modelClass);

            $this->assertSame('string', $model->getKeyType());
            $this->assertFalse($model->getIncrementing());
            $this->assertTrue($reflection->hasMethod('newUniqueId'));
            $this->assertStringNotContainsString('payload', strtolower(implode(' ', $model->getFillable())));
        }
    }

    #[Test]
    public function ledger_actions_own_transactions_and_do_not_call_external_or_outbox_services(): void
    {
        foreach ([RecordPaymentGatewayAttemptAction::class, RecordPaymentGatewayTransactionAction::class, RecordPaymentGatewayWebhookAction::class] as $actionClass) {
            $source = file_get_contents((new ReflectionClass($actionClass))->getFileName());

            $this->assertIsString($source);
            $this->assertStringContainsString('DB::transaction(', $source);
            $this->assertStringNotContainsString('PaymentGatewayProvider', $source);
            $this->assertStringNotContainsString('RecordOutboxEventAction', $source);
            $this->assertStringNotContainsString('Http::', $source);
            $this->assertStringNotContainsString('env(', $source);
        }
    }

    #[Test]
    public function ledger_migrations_forbid_sensitive_raw_data_and_database_uuid_generation(): void
    {
        $migrationPaths = glob(database_path('migrations/*payment_gateway_*.php'));

        $this->assertCount(3, $migrationPaths);

        foreach ($migrationPaths as $migrationPath) {
            $source = file_get_contents($migrationPath);

            $this->assertIsString($source);
            $this->assertStringNotContainsString('gen_random_uuid', strtolower($source));
            $this->assertStringNotContainsString('card_number', strtolower($source));
            $this->assertStringNotContainsString('cvv', strtolower($source));
            $this->assertStringNotContainsString('secret_key', strtolower($source));
            $this->assertStringNotContainsString('payload)', strtolower($source));
        }
    }

    #[Test]
    public function manual_commerce_actions_are_not_coupled_to_the_gateway_ledger(): void
    {
        $paths = glob(app_path('Modules/Commerce/Application/Actions/*.php')) ?: [];

        foreach ($paths as $path) {
            $source = file_get_contents($path);

            $this->assertIsString($source);
            $this->assertStringNotContainsString('RecordPaymentGateway', $source, $path);
        }
    }

    private function publicRoutes(): string
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->methods()[0].' '.$route->uri())
            ->implode("\n");
    }
}
