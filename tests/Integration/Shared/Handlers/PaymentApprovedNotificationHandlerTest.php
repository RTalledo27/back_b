<?php

declare(strict_types=1);

namespace Tests\Integration\Shared\Handlers;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\Shared\Infrastructure\Outbox\Handlers\PaymentApprovedNotificationHandler;
use App\Notifications\Domain\PaymentApprovedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PaymentApprovedNotificationHandlerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeHandler(): PaymentApprovedNotificationHandler
    {
        return $this->app->make(PaymentApprovedNotificationHandler::class);
    }

    private function makePayload(User $buyer, Payment $payment, Order $order, Game $game): array
    {
        return [
            'schema_version' => 1,
            'buyer_user_id' => $buyer->id,
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'game_id' => $game->id,
        ];
    }

    private function setupScenario(): array
    {
        $buyer = User::factory()->create();
        $game = Game::create([
            'slug' => 'g-'.Str::random(6), 'name' => 'Test', 'number_min' => 1, 'number_max' => 10,
            'hits_required' => 2, 'ticket_price_cents' => 100, 'prize_cents' => 500,
            'currency' => 'PEN', 'draw_interval_seconds' => 30, 'auto_draw_enabled' => false,
            'status' => GameStatus::SalesClosed,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id, 'status' => 'paid',
            'subtotal_cents' => 100, 'total_cents' => 100, 'currency' => 'PEN',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 100, 'currency' => 'PEN',
            'method' => PaymentMethod::Manual, 'status' => PaymentStatus::Approved,
        ]);

        return [$buyer, $payment, $order, $game];
    }

    public function test_sends_notification_and_creates_delivery(): void
    {
        Notification::fake();

        [$buyer, $payment, $order, $game] = $this->setupScenario();
        $outboxEventId = (string) Str::uuid7();

        $this->makeHandler()->handle($outboxEventId, $this->makePayload($buyer, $payment, $order, $game));

        Notification::assertSentTo($buyer, PaymentApprovedNotification::class);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertDatabaseHas('notification_deliveries', [
            'outbox_event_id' => $outboxEventId,
            'status' => NotificationDelivery::STATUS_QUEUED,
        ]);
    }

    public function test_does_not_send_if_already_queued(): void
    {
        Notification::fake();

        [$buyer, $payment, $order, $game] = $this->setupScenario();
        $outboxEventId = (string) Str::uuid7();
        $payload = $this->makePayload($buyer, $payment, $order, $game);

        $this->makeHandler()->handle($outboxEventId, $payload);
        $this->makeHandler()->handle($outboxEventId, $payload);

        Notification::assertSentToTimes($buyer, PaymentApprovedNotification::class, 1);
    }

    public function test_does_not_send_if_already_sent(): void
    {
        Notification::fake();

        [$buyer, $payment, $order, $game] = $this->setupScenario();
        $outboxEventId = (string) Str::uuid7();

        $delivery = NotificationDelivery::claim($outboxEventId, 'payment_approved', $buyer->id, 'mail');
        $delivery->markSent();

        $this->makeHandler()->handle($outboxEventId, $this->makePayload($buyer, $payment, $order, $game));

        Notification::assertNothingSent();
    }

    public function test_does_not_send_if_pending_fresh(): void
    {
        Notification::fake();

        [$buyer, $payment, $order, $game] = $this->setupScenario();
        $outboxEventId = (string) Str::uuid7();

        NotificationDelivery::claim($outboxEventId, 'payment_approved', $buyer->id, 'mail');

        $this->makeHandler()->handle($outboxEventId, $this->makePayload($buyer, $payment, $order, $game));

        Notification::assertNothingSent();
    }

    public function test_throws_if_user_not_found(): void
    {
        $outboxEventId = (string) Str::uuid7();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/user.*not found/i');

        $this->makeHandler()->handle($outboxEventId, [
            'buyer_user_id' => 99999,
            'payment_id' => (string) Str::uuid7(),
            'order_id' => (string) Str::uuid7(),
        ]);
    }

    public function test_marks_failed_if_payment_not_approved(): void
    {
        Notification::fake();

        [$buyer, $payment, $order, $game] = $this->setupScenario();
        $payment->update(['status' => PaymentStatus::Rejected]);
        $outboxEventId = (string) Str::uuid7();

        $this->makeHandler()->handle($outboxEventId, $this->makePayload($buyer, $payment, $order, $game));

        Notification::assertNothingSent();
        $this->assertDatabaseHas('notification_deliveries', [
            'outbox_event_id' => $outboxEventId,
            'status' => NotificationDelivery::STATUS_FAILED,
        ]);
    }

    public function test_deduplication_key_format(): void
    {
        Notification::fake();

        [$buyer, $payment, $order, $game] = $this->setupScenario();
        $outboxEventId = (string) Str::uuid7();

        $this->makeHandler()->handle($outboxEventId, $this->makePayload($buyer, $payment, $order, $game));

        $this->assertDatabaseHas('notification_deliveries', [
            'deduplication_key' => "{$outboxEventId}:{$buyer->id}:mail",
        ]);
    }
}
