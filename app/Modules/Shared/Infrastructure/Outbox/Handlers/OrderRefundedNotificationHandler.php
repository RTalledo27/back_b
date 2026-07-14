<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Outbox\Handlers;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Refund;
use App\Modules\Shared\Infrastructure\Outbox\OutboxEventDeferred;
use App\Notifications\Domain\OrderRefundedNotification;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class OrderRefundedNotificationHandler
{
    public function handle(string $outboxEventId, array $payload): void
    {
        $buyerUserId = (int) ($payload['buyer_user_id'] ?? 0);
        $orderId = (string) ($payload['order_id'] ?? '');
        $refundId = (string) ($payload['refund_id'] ?? '');

        [$delivery, $wasJustCreated] = NotificationDelivery::claimForHandler(
            outboxEventId: $outboxEventId,
            eventType: 'order_refunded',
            recipientUserId: $buyerUserId,
            channel: NotificationDelivery::CHANNEL_MAIL,
        );

        if ($delivery->isFinalOrQueued()) {
            return;
        }

        if (! $wasJustCreated && $delivery->isPendingFresh()) {
            throw OutboxEventDeferred::forSeconds(NotificationDelivery::PENDING_FRESH_SECONDS);
        }

        $user = User::find($buyerUserId);
        if ($user === null) {
            throw new RuntimeException(
                "OrderRefundedNotificationHandler: user {$buyerUserId} not found for outbox event {$outboxEventId}."
            );
        }

        $refund = Refund::find($refundId);
        if ($refund === null) {
            Log::warning('outbox.order_refunded: refund not found', [
                'outbox_event_id' => $outboxEventId,
                'refund_id' => $refundId,
                'order_id' => $orderId,
            ]);
            $delivery->markFailed('refund_not_found');

            return;
        }

        $order = Order::find($orderId);
        if ($order === null) {
            Log::warning('outbox.order_refunded: order not found', [
                'outbox_event_id' => $outboxEventId,
                'order_id' => $orderId,
            ]);
            $delivery->markFailed('order_not_found');

            return;
        }

        $user->notify(new OrderRefundedNotification($orderId, $refundId));

        $delivery->markQueued();
    }
}
