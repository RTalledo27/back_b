<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Handlers;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\Refund;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\Shared\Infrastructure\Outbox\Handlers\OrderRefundedNotificationHandler;
use App\Notifications\Domain\OrderRefundedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class OrderRefundedNotificationHandlerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeHandler(): OrderRefundedNotificationHandler
    {
        return $this->app->make(OrderRefundedNotificationHandler::class);
    }

    private function setupScenario(): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $game = Game::create([
            'slug' => 'g-'.Str::random(6), 'name' => 'Test', 'number_min' => 1, 'number_max' => 10,
            'hits_required' => 2, 'ticket_price_cents' => 100, 'prize_cents' => 500,
            'currency' => 'PEN', 'draw_interval_seconds' => 30, 'auto_draw_enabled' => false,
            'status' => GameStatus::SalesClosed,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id, 'status' => OrderStatus::Refunded,
            'subtotal_cents' => 100, 'total_cents' => 100, 'currency' => 'PEN',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 100, 'currency' => 'PEN',
            'method' => PaymentMethod::Manual, 'status' => PaymentStatus::Refunded,
        ]);
        $refund = Refund::create([
            'order_id' => $order->id, 'payment_id' => $payment->id,
            'amount_cents' => 100, 'currency' => 'PEN',
            'reason' => 'admin_refund', 'idempotency_key_hash' => hash('sha256', (string) Str::uuid7()),
            'request_fingerprint' => hash('sha256', (string) Str::uuid7()),
            'processed_by_user_id' => $admin->id, 'processed_at' => now(),
        ]);

        return [$buyer, $order, $payment, $refund];
    }

    private function makePayload(User $buyer, Order $order, Payment $payment, Refund $refund): array
    {
        return [
            'schema_version' => 1,
            'buyer_user_id' => $buyer->id,
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'refund_id' => $refund->id,
        ];
    }

    public function test_sends_notification_and_creates_delivery(): void
    {
        Notification::fake();

        [$buyer, $order, $payment, $refund] = $this->setupScenario();
        $outboxEventId = (string) Str::uuid7();

        $this->makeHandler()->handle($outboxEventId, $this->makePayload($buyer, $order, $payment, $refund));

        Notification::assertSentTo($buyer, OrderRefundedNotification::class);
        $this->assertDatabaseHas('notification_deliveries', [
            'outbox_event_id' => $outboxEventId,
            'status' => NotificationDelivery::STATUS_QUEUED,
        ]);
    }

    public function test_does_not_send_if_already_queued(): void
    {
        Notification::fake();

        [$buyer, $order, $payment, $refund] = $this->setupScenario();
        $outboxEventId = (string) Str::uuid7();
        $payload = $this->makePayload($buyer, $order, $payment, $refund);

        $this->makeHandler()->handle($outboxEventId, $payload);
        $this->makeHandler()->handle($outboxEventId, $payload);

        Notification::assertSentToTimes($buyer, OrderRefundedNotification::class, 1);
    }

    public function test_throws_if_user_not_found(): void
    {
        $this->expectException(RuntimeException::class);

        $this->makeHandler()->handle((string) Str::uuid7(), [
            'buyer_user_id' => 99999,
            'order_id' => (string) Str::uuid7(),
            'refund_id' => (string) Str::uuid7(),
        ]);
    }

    public function test_marks_failed_if_refund_not_found(): void
    {
        Notification::fake();

        [$buyer] = $this->setupScenario();

        $this->makeHandler()->handle((string) Str::uuid7(), [
            'buyer_user_id' => $buyer->id,
            'order_id' => (string) Str::uuid7(),
            'refund_id' => (string) Str::uuid7(),
        ]);

        Notification::assertNothingSent();
        $this->assertDatabaseHas('notification_deliveries', ['status' => NotificationDelivery::STATUS_FAILED]);
    }
}
