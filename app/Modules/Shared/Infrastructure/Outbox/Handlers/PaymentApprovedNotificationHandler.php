<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Outbox\Handlers;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Notifications\Domain\PaymentApprovedNotification;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class PaymentApprovedNotificationHandler
{
    public function handle(string $outboxEventId, array $payload): void
    {
        $buyerUserId = (int) ($payload['buyer_user_id'] ?? 0);
        $paymentId = (string) ($payload['payment_id'] ?? '');
        $orderId = (string) ($payload['order_id'] ?? '');

        [$delivery, $wasJustCreated] = NotificationDelivery::claimForHandler(
            outboxEventId: $outboxEventId,
            eventType: 'payment_approved',
            recipientUserId: $buyerUserId,
            channel: NotificationDelivery::CHANNEL_MAIL,
        );

        if ($delivery->isFinalOrQueued()) {
            return;
        }

        if (! $wasJustCreated && $delivery->isPendingFresh()) {
            return;
        }

        $user = User::find($buyerUserId);
        if ($user === null) {
            throw new RuntimeException(
                "PaymentApprovedNotificationHandler: user {$buyerUserId} not found for outbox event {$outboxEventId}."
            );
        }

        $payment = Payment::find($paymentId);
        if ($payment === null || $payment->status !== PaymentStatus::Approved) {
            Log::warning('outbox.payment_approved: payment not in approved state', [
                'outbox_event_id' => $outboxEventId,
                'payment_id' => $paymentId,
            ]);
            $delivery->markFailed('payment_not_in_approved_state');

            return;
        }

        $user->notify(new PaymentApprovedNotification($paymentId, $orderId));

        $delivery->markQueued();
    }
}
