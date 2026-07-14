<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use App\Notifications\Domain\GameWinnerDeclaredNotification;
use App\Notifications\Domain\OrderRefundedNotification;
use App\Notifications\Domain\PaymentApprovedNotification;
use App\Notifications\Domain\PaymentRejectedNotification;
use App\Notifications\Domain\WinnerPayoutRegisteredNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

final class NotificationRuntimeReadinessTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'cache.default' => 'array',
        ]);
    }

    public function test_runtime_tables_and_testing_overrides_are_available(): void
    {
        $this->assertTrue(Schema::hasTable('notification_deliveries'));
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));

        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('database', config('queue.connections.database.driver'));
        $this->assertSame('jobs', config('queue.connections.database.table'));
        $this->assertSame('failed_jobs', config('queue.failed.table'));
    }

    public function test_domain_notifications_are_queued_mail_notifications(): void
    {
        $notificationClasses = [
            GameWinnerDeclaredNotification::class,
            OrderRefundedNotification::class,
            PaymentApprovedNotification::class,
            PaymentRejectedNotification::class,
            WinnerPayoutRegisteredNotification::class,
        ];

        foreach ($notificationClasses as $notificationClass) {
            $reflection = new ReflectionClass($notificationClass);
            $notification = $reflection->newInstanceArgs(
                array_fill(0, $reflection->getConstructor()?->getNumberOfParameters() ?? 0, 'runtime-test-id'),
            );

            $this->assertInstanceOf(Notification::class, $notification);
            $this->assertTrue(
                $reflection->implementsInterface(ShouldQueue::class),
                "{$notificationClass} must implement ShouldQueue.",
            );
            $this->assertSame(['mail'], $notification->via((object) []));
        }
    }
}
