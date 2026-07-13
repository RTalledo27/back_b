<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Outbox;

use App\Models\OutboxEvent;
use App\Modules\Shared\Infrastructure\Outbox\Handlers\GameWinnerDeclaredNotificationHandler;
use App\Modules\Shared\Infrastructure\Outbox\Handlers\OrderRefundedNotificationHandler;
use App\Modules\Shared\Infrastructure\Outbox\Handlers\PaymentApprovedNotificationHandler;
use App\Modules\Shared\Infrastructure\Outbox\Handlers\PaymentRejectedNotificationHandler;
use App\Modules\Shared\Infrastructure\Outbox\Handlers\WinnerPayoutRegisteredNotificationHandler;
use RuntimeException;

/**
 * Routes a claimed outbox event to the appropriate consumer handler.
 *
 * Phase 9.2: all 5 handlers replaced with real email notification handlers.
 *
 * Unknown event_type throws RuntimeException so the processor marks failed_at
 * rather than silently discarding events.
 */
class OutboxEventDispatcher
{
    public function __construct(
        private readonly PaymentApprovedNotificationHandler $paymentApprovedHandler,
        private readonly PaymentRejectedNotificationHandler $paymentRejectedHandler,
        private readonly OrderRefundedNotificationHandler $orderRefundedHandler,
        private readonly WinnerPayoutRegisteredNotificationHandler $winnerPayoutRegisteredHandler,
        private readonly GameWinnerDeclaredNotificationHandler $gameWinnerDeclaredHandler,
    ) {}

    /**
     * Dispatch a single outbox event to its consumer.
     *
     * Throws RuntimeException for unknown event types so they surface
     * as permanent failures rather than silently disappearing.
     */
    public function dispatch(OutboxEvent $event): void
    {
        match ($event->event_type) {
            'payment_approved' => $this->paymentApprovedHandler->handle($event->id, $event->payload),
            'payment_rejected' => $this->paymentRejectedHandler->handle($event->id, $event->payload),
            'order_refunded' => $this->orderRefundedHandler->handle($event->id, $event->payload),
            'winner_payout_registered' => $this->winnerPayoutRegisteredHandler->handle($event->id, $event->payload),
            'game_winner_declared' => $this->gameWinnerDeclaredHandler->handle($event->id, $event->payload),
            default => throw new RuntimeException(
                "OutboxEventDispatcher: unknown event_type '{$event->event_type}'."
            ),
        };
    }
}
