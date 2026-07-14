<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Outbox\Handlers;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Shared\Infrastructure\Outbox\OutboxEventDeferred;
use App\Notifications\Domain\PaymentRejectedNotification;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class PaymentRejectedNotificationHandler
{
    public function handle(string $outboxEventId, array $payload): void
    {
        $buyerUserId = (int) ($payload['buyer_user_id'] ?? 0);
        $paymentId = (string) ($payload['payment_id'] ?? '');
        $orderId = (string) ($payload['order_id'] ?? '');

        [$delivery, $wasJustCreated] = NotificationDelivery::claimForHandler(
            outboxEventId: $outboxEventId,
            eventType: 'payment_rejected',
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
                "PaymentRejectedNotificationHandler: user {$buyerUserId} not found for outbox event {$outboxEventId}."
            );
        }

        $payment = Payment::find($paymentId);
        if ($payment === null || $payment->status !== PaymentStatus::Rejected) {
            Log::warning('outbox.payment_rejected: payment not in rejected state', [
                'outbox_event_id' => $outboxEventId,
                'payment_id' => $paymentId,
            ]);
            $delivery->markFailed('payment_not_in_rejected_state');

            return;
        }

        $user->notify(new PaymentRejectedNotification($paymentId, $orderId));

        $delivery->markQueued();
    }
}
