<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Phase101PaymentGatewayContractTest extends TestCase
{
    public function test_phase_documentation_describes_only_the_audit_contract(): void
    {
        $contents = $this->read($this->rootPath('docs/phase-10.md'));

        foreach ([
            'Fase 10.1',
            'PaymentGatewayProvider',
            'PaymentGatewayTransaction',
            'PaymentGatewayWebhook',
            'PaymentGatewayAttempt',
            'Idempotency-Key',
            'at-least-once',
            'Bloque 10.2',
            'No se afirma',
        ] as $requiredText) {
            self::assertStringContainsString($requiredText, $contents);
        }
    }

    public function test_exactly_five_domain_notifications_are_queued_mail_notifications(): void
    {
        $directory = $this->rootPath('app/Notifications/Domain');
        $files = $this->phpFiles($directory);

        self::assertCount(5, $files);

        foreach ($files as $file) {
            $contents = $this->read($file);

            self::assertStringContainsString('implements ShouldQueue', $contents, $file);
            self::assertStringContainsString("return ['mail'];", $contents, $file);
            self::assertDoesNotMatchRegularExpression('/return\s+\[[^\]]*,/', $contents, $file);
        }
    }

    public function test_email_verification_notification_remains_queued_and_mail_only(): void
    {
        $contents = $this->read($this->rootPath('app/Notifications/Auth/VerifyEmailNotification.php'));

        self::assertStringContainsString('implements ShouldQueue', $contents);
        self::assertStringContainsString("return ['mail'];", $contents);
    }

    public function test_no_real_provider_sdk_classes_credentials_or_webhook_routes_exist(): void
    {
        foreach ($this->phpFiles($this->rootPath('app')) as $file) {
            $contents = $this->read($file);

            self::assertDoesNotMatchRegularExpression(
                '/(?:^|\n)\s*use\s+[^;\n]*(?:Culqi|Niubiz|Stripe|MercadoPago)/i',
                $contents,
                $file,
            );

            self::assertDoesNotMatchRegularExpression(
                '/\b(?:Culqi|Niubiz|Stripe|MercadoPago)(?:Client|Gateway)?\s*(?:::|\()/i',
                $contents,
                $file,
            );
        }

        $envExample = $this->read($this->rootPath('.env.example'));
        self::assertDoesNotMatchRegularExpression(
            '/\b(?:CULQI|NIUBIZ|STRIPE|MERCADOPAGO)[A-Z0-9_]*\s*=/i',
            $envExample,
        );

        foreach ($this->phpFiles($this->rootPath('routes')) as $file) {
            $contents = $this->read($file);

            self::assertDoesNotMatchRegularExpression(
                '#/webhooks?/payments(?:/|\b)#i',
                $contents,
                $file,
            );
        }
    }

    public function test_outbox_dispatcher_keeps_the_current_five_event_types(): void
    {
        $dispatcher = $this->read(
            $this->rootPath('app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php'),
        );

        foreach ([
            'payment_approved',
            'payment_rejected',
            'order_refunded',
            'winner_payout_registered',
            'game_winner_declared',
        ] as $eventType) {
            self::assertStringContainsString("'{$eventType}'", $dispatcher);
        }

        self::assertDoesNotMatchRegularExpression('/(?:gateway|webhook|culqi|niubiz|stripe)/i', $dispatcher);
        self::assertCount(5, $this->phpFiles($this->rootPath('app/Modules/Shared/Infrastructure/Outbox/Handlers')));
    }

    public function test_application_actions_do_not_send_notifications_directly(): void
    {
        foreach ($this->phpFiles($this->rootPath('app/Modules')) as $file) {
            if (! str_contains(str_replace('\\', '/', $file), '/Application/Actions/')) {
                continue;
            }

            $contents = $this->read($file);

            self::assertDoesNotMatchRegularExpression('/\bnotify\s*\(|\b(?:Mail|Notification)::/', $contents, $file);
        }
    }

    public function test_notification_delivery_model_does_not_store_pii_or_provider_payloads(): void
    {
        $contents = $this->read($this->rootPath('app/Models/NotificationDelivery.php'));

        self::assertDoesNotMatchRegularExpression(
            '/[\'\"](?:email|phone|name|address|payload|card_number|cvv|secret|token)[\'\"]\s*=>/i',
            $contents,
        );
        self::assertStringContainsString("'deduplication_key'", $contents);
        self::assertStringNotContainsString("'payload'", $contents);
    }

    public function test_phase_runtime_and_scope_are_documented(): void
    {
        $envExample = $this->read($this->rootPath('.env.example'));
        $documentation = $this->read($this->rootPath('docs/phase-10.md'));

        self::assertStringContainsString('QUEUE_CONNECTION=database', $envExample);
        self::assertStringContainsString('MAIL_HOST=mailpit', $envExample);
        self::assertStringContainsString('worker', $documentation);
        self::assertStringContainsString('Outbox', $documentation);
        self::assertStringContainsString('webhook', strtolower($documentation));
        self::assertStringContainsString('no implementa', strtolower($documentation));
    }

    private function rootPath(string $relativePath): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
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

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        self::assertNotFalse($contents, $path);

        return $contents;
    }
}
