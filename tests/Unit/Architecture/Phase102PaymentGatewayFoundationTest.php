<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Phase102PaymentGatewayFoundationTest extends TestCase
{
    public function test_internal_gateway_contracts_and_fake_implementations_exist(): void
    {
        foreach ([
            'app/Modules/Commerce/Application/Gateway/PaymentGatewayProvider.php',
            'app/Modules/Commerce/Application/Gateway/PaymentGatewayProviderRegistry.php',
            'app/Modules/Commerce/Application/Gateway/PaymentGatewayTransactionStatus.php',
            'app/Modules/Commerce/Application/Gateway/PaymentGatewayCreateAttemptData.php',
            'app/Modules/Commerce/Application/Gateway/PaymentGatewayCreateAttemptResult.php',
            'app/Modules/Commerce/Application/Gateway/PaymentGatewayConfirmData.php',
            'app/Modules/Commerce/Application/Gateway/PaymentGatewayConfirmResult.php',
            'app/Modules/Commerce/Application/Gateway/PaymentGatewayWebhookPayload.php',
            'app/Modules/Commerce/Application/Gateway/PaymentGatewayWebhookNormalizer.php',
            'app/Modules/Commerce/Application/Gateway/PaymentGatewayWebhookVerifier.php',
            'app/Modules/Commerce/Infrastructure/Gateway/FakePaymentGatewayProvider.php',
            'app/Modules/Commerce/Infrastructure/Gateway/FakePaymentGatewayWebhookNormalizer.php',
            'app/Modules/Commerce/Infrastructure/Gateway/FakePaymentGatewayWebhookVerifier.php',
        ] as $path) {
            self::assertFileExists($this->rootPath($path));
        }
    }

    public function test_no_real_provider_sdk_or_http_client_is_imported(): void
    {
        foreach ($this->phpFiles($this->rootPath('app/Modules/Commerce')) as $file) {
            $contents = $this->read($file);

            self::assertDoesNotMatchRegularExpression(
                '/(?:^|\n)\s*use\s+[^;\n]*(?:Culqi|Niubiz|Stripe|MercadoPago|GuzzleHttp|Http::)/i',
                $contents,
                $file,
            );
        }
    }

    public function test_no_public_webhook_controller_or_route_is_registered(): void
    {
        foreach ($this->phpFiles($this->rootPath('routes')) as $file) {
            self::assertDoesNotMatchRegularExpression(
                '#/webhooks?/payments|webhooks?\\\\payments#i',
                $this->read($file),
                $file,
            );
        }

        foreach ($this->phpFiles($this->rootPath('app/Modules/Commerce/Presentation/Http/Controllers')) as $file) {
            self::assertDoesNotMatchRegularExpression('/Webhook|Gateway/i', basename($file), $file);
        }
    }

    public function test_configuration_is_fake_sandbox_only_and_credentials_are_placeholders(): void
    {
        $config = $this->read($this->rootPath('config/payment_gateways.php'));
        $envExample = $this->read($this->rootPath('.env.example'));

        self::assertStringContainsString("'fake'", $config);
        self::assertStringContainsString("'sandbox'", $config);
        self::assertStringContainsString('PAYMENT_GATEWAY_PROVIDER=fake', $envExample);
        self::assertStringContainsString('PAYMENT_GATEWAY_ENV=sandbox', $envExample);

        foreach (['PUBLIC_KEY', 'SECRET_KEY', 'WEBHOOK_SECRET'] as $suffix) {
            self::assertMatchesRegularExpression(
                "/PAYMENT_GATEWAY_{$suffix}=\\s*$/m",
                $envExample,
            );
        }

        self::assertDoesNotMatchRegularExpression('/(?:sk_live_|pk_live_|culqi|niubiz|stripe)/i', $envExample);
    }

    public function test_no_gateway_migrations_or_new_outbox_event_types_exist(): void
    {
        foreach ($this->phpFiles($this->rootPath('database/migrations')) as $file) {
            self::assertDoesNotMatchRegularExpression('/payment_gateway_(?:attempts|transactions|webhooks)/i', $this->read($file), $file);
        }

        $dispatcher = $this->read(
            $this->rootPath('app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php'),
        );

        self::assertDoesNotMatchRegularExpression('/gateway|webhook/i', $dispatcher);
    }

    public function test_manual_commerce_actions_do_not_import_the_gateway_foundation(): void
    {
        foreach ([
            'ApprovePaymentAction.php',
            'RejectPaymentAction.php',
            'RefundOrderAction.php',
            'SubmitPaymentEvidenceAction.php',
        ] as $filename) {
            $matches = glob($this->rootPath('app/Modules/Commerce/Application/Actions/'.$filename));

            self::assertCount(1, $matches, $filename);
            self::assertDoesNotMatchRegularExpression('/PaymentGateway|Gateway/', $this->read($matches[0]), $filename);
        }
    }

    public function test_foundation_documentation_declares_the_phase_limits(): void
    {
        $documentation = $this->read($this->rootPath('docs/phase-10.md'));

        foreach ([
            'Fase 10.2',
            'FakePaymentGatewayProvider',
            'PAYMENT_GATEWAY_PROVIDER=fake',
            'Fase 10.3',
        ] as $requiredText) {
            self::assertStringContainsString($requiredText, $documentation);
        }

        self::assertStringContainsString('no se implementan', strtolower($documentation));
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

    private function rootPath(string $relativePath): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        self::assertNotFalse($contents, $path);

        return $contents;
    }
}
