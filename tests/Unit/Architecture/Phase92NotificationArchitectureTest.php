<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards for Phase 9.2 notification architecture.
 *
 * Verified invariants:
 *  1. No domain Action calls notify(), Mail::, or Notification:: directly.
 *  2. Domain Notifications live in app/Notifications/Domain — not in Auth.
 *  3. Auth Notifications are not in app/Notifications/Domain.
 *  4. Handlers do not import SMS/WhatsApp/Twilio/Vonage/gateway clients.
 *  5. No forbidden payment gateway or comms provider in notifications.
 *  6. No new HTTP routes for notification endpoints.
 *  7. No new outbox event_types beyond the 5 defined in Phase 8.
 *  8. UniqueConstraintViolationException is not used as normal flow.
 *  9. Domain Notifications implement ShouldQueue.
 * 10. VerifyEmailNotification implements ShouldQueue.
 */
final class Phase92NotificationArchitectureTest extends TestCase
{
    private const COMMERCE_ACTIONS = __DIR__.'/../../../app/Modules/Commerce/Application/Actions';

    private const GAME_ACTIONS = __DIR__.'/../../../app/Modules/RepeatNumberBingo/Application/Actions';

    private const HANDLERS = __DIR__.'/../../../app/Modules/Shared/Infrastructure/Outbox/Handlers';

    private const DOMAIN_NOTIFICATIONS = __DIR__.'/../../../app/Notifications/Domain';

    private const AUTH_NOTIFICATIONS = __DIR__.'/../../../app/Notifications/Auth';

    private const API_ROUTES = __DIR__.'/../../../routes/api.php';

    private const WEB_ROUTES = __DIR__.'/../../../routes/web.php';

    private function read(string $path): string
    {
        $this->assertFileExists($path, "Expected file not found: {$path}");

        return (string) file_get_contents($path);
    }

    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    // ── 1. Domain Actions do not call notify/Mail/Notification directly ────────

    public function test_commerce_actions_do_not_call_notify_directly(): void
    {
        $forbidden = ['->notify(', 'Mail::', 'Notification::'];

        foreach ($this->phpFiles(self::COMMERCE_ACTIONS) as $file) {
            $content = (string) file_get_contents($file);
            foreach ($forbidden as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern, $content,
                    basename($file)." must not call '{$pattern}' directly — use handlers via Outbox."
                );
            }
        }

        $this->assertTrue(true);
    }

    public function test_game_actions_do_not_call_notify_directly(): void
    {
        $forbidden = ['->notify(', 'Mail::', 'Notification::'];

        foreach ($this->phpFiles(self::GAME_ACTIONS) as $file) {
            $content = (string) file_get_contents($file);
            foreach ($forbidden as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern, $content,
                    basename($file)." must not call '{$pattern}' directly."
                );
            }
        }

        $this->assertTrue(true);
    }

    // ── 2. Domain Notifications not in Auth namespace ─────────────────────────

    public function test_domain_notifications_are_in_domain_directory(): void
    {
        $domainNotifications = $this->phpFiles(self::DOMAIN_NOTIFICATIONS);
        $this->assertNotEmpty($domainNotifications, 'Domain notifications directory must not be empty.');

        foreach ($domainNotifications as $file) {
            $content = (string) file_get_contents($file);
            $this->assertStringContainsString(
                'namespace App\Notifications\Domain',
                $content,
                basename($file).' must be in the Domain namespace.'
            );
        }
    }

    // ── 3. Auth Notifications not in Domain namespace ─────────────────────────

    public function test_auth_notifications_are_not_in_domain_directory(): void
    {
        $domainFiles = array_map('basename', $this->phpFiles(self::DOMAIN_NOTIFICATIONS));

        foreach ($this->phpFiles(self::AUTH_NOTIFICATIONS) as $file) {
            $this->assertNotContains(
                basename($file),
                $domainFiles,
                basename($file).' must not be duplicated in Notifications/Domain.'
            );
        }

        $this->assertTrue(true);
    }

    // ── 4. Handlers do not import forbidden clients ───────────────────────────

    public function test_handlers_do_not_import_sms_or_whatsapp_clients(): void
    {
        $forbidden = ['Twilio', 'Vonage', 'WhatsApp', 'Sms', 'Http::post', 'Http::put', 'curl_exec'];

        foreach ($this->phpFiles(self::HANDLERS) as $file) {
            $content = (string) file_get_contents($file);
            foreach ($forbidden as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern, $content,
                    basename($file)." must not import forbidden client '{$pattern}'."
                );
            }
        }

        $this->assertTrue(true);
    }

    // ── 5. Domain Notifications do not contain forbidden providers ────────────

    public function test_domain_notifications_have_no_forbidden_providers(): void
    {
        $forbidden = ['Twilio', 'Vonage', 'Stripe', 'Culqi', 'Niubiz', 'WhatsApp', 'Sms', 'curl_exec'];

        foreach ($this->phpFiles(self::DOMAIN_NOTIFICATIONS) as $file) {
            $content = (string) file_get_contents($file);
            foreach ($forbidden as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern, $content,
                    basename($file)." must not reference forbidden provider '{$pattern}'."
                );
            }
        }

        $this->assertTrue(true);
    }

    // ── 6. No new notification HTTP routes ────────────────────────────────────

    public function test_api_routes_have_no_notification_endpoints(): void
    {
        $content = $this->read(self::API_ROUTES);

        // Guard against standalone notification route paths like '/notifications'
        // or '/notification'. The auth '/email/verification-notification' path
        // does not start with '/notification' so it is not caught here.
        $this->assertStringNotContainsString(
            "'/notification",
            $content,
            "api.php must not expose standalone notification route paths (e.g. '/notifications')."
        );
    }

    // ── 7. No new outbox event_types ──────────────────────────────────────────

    public function test_no_new_outbox_event_types_beyond_phase_8(): void
    {
        $allowedTypes = [
            'payment_approved',
            'payment_rejected',
            'order_refunded',
            'winner_payout_registered',
            'game_winner_declared',
        ];

        $dispatcherFile = __DIR__.'/../../../app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php';
        $content = $this->read($dispatcherFile);

        preg_match_all("/'([a-z_]+)'\s*=>/", $content, $matches);
        $foundTypes = $matches[1] ?? [];

        foreach ($foundTypes as $type) {
            $this->assertContains(
                $type,
                $allowedTypes,
                "OutboxEventDispatcher references unexpected event_type '{$type}'."
            );
        }
    }

    // ── 8. UniqueConstraintViolationException not used as normal flow ─────────

    public function test_unique_constraint_violation_not_used_as_flow(): void
    {
        $handlerFiles = $this->phpFiles(self::HANDLERS);
        $modelFile = __DIR__.'/../../../app/Models/NotificationDelivery.php';

        $filesToCheck = array_merge($handlerFiles, [$modelFile]);

        foreach ($filesToCheck as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $content = (string) file_get_contents($file);
            $this->assertStringNotContainsString(
                'UniqueConstraintViolationException',
                $content,
                basename($file).' must not catch UniqueConstraintViolationException as normal flow — use ON CONFLICT DO NOTHING.'
            );
        }

        $this->assertTrue(true);
    }

    // ── 9. Domain Notifications implement ShouldQueue ─────────────────────────

    public function test_domain_notifications_implement_should_queue(): void
    {
        $domainNotifications = $this->phpFiles(self::DOMAIN_NOTIFICATIONS);
        $this->assertNotEmpty($domainNotifications);

        foreach ($domainNotifications as $file) {
            $content = (string) file_get_contents($file);
            $this->assertStringContainsString(
                'ShouldQueue',
                $content,
                basename($file).' must implement ShouldQueue.'
            );
        }
    }

    // ── 10. VerifyEmailNotification implements ShouldQueue ───────────────────

    public function test_verify_email_notification_implements_should_queue(): void
    {
        $file = self::AUTH_NOTIFICATIONS.'/VerifyEmailNotification.php';
        $content = $this->read($file);

        $this->assertStringContainsString(
            'ShouldQueue',
            $content,
            'VerifyEmailNotification must implement ShouldQueue.'
        );
    }
}
