<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Models\NotificationDelivery;
use App\Modules\Shared\Infrastructure\Outbox\OutboxEventDispatcher;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Notifications\Domain\GameWinnerDeclaredNotification;
use App\Notifications\Domain\OrderRefundedNotification;
use App\Notifications\Domain\PaymentApprovedNotification;
use App\Notifications\Domain\PaymentRejectedNotification;
use App\Notifications\Domain\WinnerPayoutRegisteredNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Final closure guards for the Phase 9 notification boundary.
 */
final class Phase94NotificationClosureTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const DOMAIN_NOTIFICATIONS = __DIR__.'/../../../app/Notifications/Domain';

    private const AUTH_NOTIFICATIONS = __DIR__.'/../../../app/Notifications/Auth';

    private const HANDLERS = __DIR__.'/../../../app/Modules/Shared/Infrastructure/Outbox/Handlers';

    private const MODULES = __DIR__.'/../../../app/Modules';

    private const DOCS = __DIR__.'/../../../docs/phase-9.md';

    private const ENV_EXAMPLE = __DIR__.'/../../../.env.example';

    /** @var list<class-string<Notification>> */
    private const DOMAIN_NOTIFICATION_CLASSES = [
        GameWinnerDeclaredNotification::class,
        OrderRefundedNotification::class,
        PaymentApprovedNotification::class,
        PaymentRejectedNotification::class,
        WinnerPayoutRegisteredNotification::class,
    ];

    /** @var list<class-string> */
    private const HANDLER_CLASSES = [
        'App\\Modules\\Shared\\Infrastructure\\Outbox\\Handlers\\GameWinnerDeclaredNotificationHandler',
        'App\\Modules\\Shared\\Infrastructure\\Outbox\\Handlers\\OrderRefundedNotificationHandler',
        'App\\Modules\\Shared\\Infrastructure\\Outbox\\Handlers\\PaymentApprovedNotificationHandler',
        'App\\Modules\\Shared\\Infrastructure\\Outbox\\Handlers\\PaymentRejectedNotificationHandler',
        'App\\Modules\\Shared\\Infrastructure\\Outbox\\Handlers\\WinnerPayoutRegisteredNotificationHandler',
    ];

    /** @var list<string> */
    private const EVENT_TYPES = [
        'payment_approved',
        'payment_rejected',
        'order_refunded',
        'winner_payout_registered',
        'game_winner_declared',
    ];

    public function test_phase9_has_exactly_five_domain_notifications_and_handlers(): void
    {
        $notificationFiles = $this->phpFiles(self::DOMAIN_NOTIFICATIONS);
        $handlerFiles = $this->phpFiles(self::HANDLERS);

        $this->assertCount(5, $notificationFiles);
        $this->assertCount(5, $handlerFiles);
        $this->assertEqualsCanonicalizing(
            array_map(fn (string $class): string => class_basename($class), self::DOMAIN_NOTIFICATION_CLASSES),
            array_map(fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), $notificationFiles),
        );
        $this->assertEqualsCanonicalizing(
            array_map(fn (string $class): string => class_basename($class), self::HANDLER_CLASSES),
            array_map(fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), $handlerFiles),
        );

        $constructor = (new ReflectionClass(OutboxEventDispatcher::class))->getConstructor();
        $this->assertNotNull($constructor);

        $handlerTypes = array_map(
            fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );

        $this->assertEqualsCanonicalizing(self::HANDLER_CLASSES, $handlerTypes);
    }

    public function test_domain_notifications_are_queued_and_mail_only(): void
    {
        foreach (self::DOMAIN_NOTIFICATION_CLASSES as $notificationClass) {
            $reflection = new ReflectionClass($notificationClass);
            $notification = $reflection->newInstanceArgs(
                array_fill(0, $reflection->getConstructor()?->getNumberOfParameters() ?? 0, 'phase94-test-id'),
            );

            $this->assertTrue($reflection->implementsInterface(ShouldQueue::class));
            $this->assertSame(['mail'], $notification->via(null));
        }

        $authReflection = new ReflectionClass(VerifyEmailNotification::class);
        $authNotification = $authReflection->newInstance();

        $this->assertTrue($authReflection->implementsInterface(ShouldQueue::class));
        $this->assertSame(['mail'], $authNotification->via(null));
    }

    public function test_dispatcher_exposes_only_the_five_approved_event_types(): void
    {
        $dispatcherFile = __DIR__.'/../../../app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php';
        $content = $this->read($dispatcherFile);

        preg_match_all("/'([a-z][a-z0-9_]*)'\\s*=>/", $content, $matches);

        $this->assertSame(self::EVENT_TYPES, $matches[1] ?? []);
    }

    public function test_notification_scope_has_no_whatsapp_sms_or_gateway_dependencies(): void
    {
        $forbidden = [
            'whatsapp',
            'sms',
            'twilio',
            'vonage',
            'mailgun',
            'postmark',
            'sendgrid',
            'guzzle',
            'curl_exec',
            'http::',
        ];

        foreach (array_merge($this->phpFiles(self::HANDLERS), $this->phpFiles(self::DOMAIN_NOTIFICATIONS)) as $file) {
            $content = strtolower($this->read($file));

            foreach ($forbidden as $pattern) {
                $this->assertStringNotContainsString($pattern, $content, "Forbidden dependency '{$pattern}' in {$file}.");
            }
        }
    }

    public function test_domain_actions_do_not_send_notifications(): void
    {
        $forbidden = ['->notify(', 'Mail::', 'Notification::'];
        $actionDirectories = glob(self::MODULES.'/*/Application/Actions', GLOB_ONLYDIR) ?: [];

        foreach ($actionDirectories as $directory) {
            foreach ($this->phpFiles($directory) as $file) {
                $content = $this->read($file);

                foreach ($forbidden as $pattern) {
                    $this->assertStringNotContainsString(
                        $pattern,
                        $content,
                        "Domain Action {$file} must not call '{$pattern}'.",
                    );
                }
            }
        }
    }

    public function test_only_the_existing_email_verification_notification_route_exists(): void
    {
        $notificationRoutes = [];

        foreach (app('router')->getRoutes() as $route) {
            if (str_contains($route->uri(), 'notification')) {
                $notificationRoutes[] = [$route->uri(), $route->methods()];
            }
        }

        $this->assertSame([
            ['api/v1/auth/email/verification-notification', ['POST']],
        ], $notificationRoutes);
    }

    public function test_notification_delivery_has_only_deduplication_unique_constraint_and_no_pii_columns(): void
    {
        $uniqueIndexes = DB::select(
            "SELECT indexdef
             FROM pg_indexes
             WHERE schemaname = current_schema()
               AND tablename = 'notification_deliveries'
               AND indexdef ILIKE '%UNIQUE%'
               AND indexdef ILIKE '%deduplication_key%'",
        );

        $this->assertCount(1, $uniqueIndexes);

        $columns = Schema::getColumnListing('notification_deliveries');
        $piiColumns = [
            'email',
            'name',
            'phone',
            'address',
            'password',
            'token',
            'document',
            'message',
            'body',
        ];

        $this->assertSame([], array_values(array_intersect($piiColumns, $columns)));
        $this->assertSame([], array_values(array_intersect($piiColumns, (new NotificationDelivery)->getFillable())));
    }

    public function test_runtime_contract_and_final_documentation_are_present(): void
    {
        $env = $this->read(self::ENV_EXAMPLE);
        $docs = $this->read(self::DOCS);

        $this->assertStringContainsString('QUEUE_CONNECTION=database', $env);
        $this->assertStringContainsString('MAIL_HOST=mailpit', $env);
        $this->assertStringContainsString('MAIL_PORT=1025', $env);

        foreach ([
            'Fase 9.4',
            'queue:work database',
            'ProcessOutboxEventsJob',
            'Mailpit',
            'best-effort idempotency',
            'pending',
            'queued_at',
            'sent_at',
            'WhatsApp',
            'proveedor SMTP real',
            'NotificationSent',
            'cleanup',
        ] as $requiredText) {
            $this->assertStringContainsString($requiredText, $docs);
        }
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
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
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
