<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Outbox;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Routes a claimed outbox event to the appropriate consumer handler.
 *
 * Phase 8.2: payment_approved handled (no-op log).
 * Phase 8.3: payment_rejected, order_refunded, winner_payout_registered,
 *             game_winner_declared added (no-op log — real providers in Phase 9).
 *
 * Unknown event_type throws RuntimeException so the processor marks failed_at
 * rather than silently discarding events.
 */
class OutboxEventDispatcher
{
    /**
     * Dispatch a single outbox event to its consumer.
     *
     * Throws RuntimeException for unknown event types so they surface
     * as permanent failures rather than silently disappearing.
     */
    public function dispatch(OutboxEvent $event): void
    {
        match ($event->event_type) {
            'payment_approved' => $this->handlePaymentApproved($event),
            'payment_rejected' => $this->handlePaymentRejected($event),
            'order_refunded' => $this->handleOrderRefunded($event),
            'winner_payout_registered' => $this->handleWinnerPayoutRegistered($event),
            'game_winner_declared' => $this->handleGameWinnerDeclared($event),
            default => throw new RuntimeException(
                "OutboxEventDispatcher: unknown event_type '{$event->event_type}'."
            ),
        };
    }

    private function handlePaymentApproved(OutboxEvent $event): void
    {
        $payload = $event->payload;

        Log::info('outbox.payment_approved.delivered', [
            'outbox_event_id' => $event->id,
            'payment_id' => $payload['payment_id'] ?? null,
            'order_id' => $payload['order_id'] ?? null,
            'game_id' => $payload['game_id'] ?? null,
            'buyer_user_id' => $payload['buyer_user_id'] ?? null,
            'schema_version' => $payload['schema_version'] ?? null,
        ]);
    }

    private function handlePaymentRejected(OutboxEvent $event): void
    {
        $payload = $event->payload;

        Log::info('outbox.payment_rejected.delivered', [
            'outbox_event_id' => $event->id,
            'payment_id' => $payload['payment_id'] ?? null,
            'order_id' => $payload['order_id'] ?? null,
            'game_id' => $payload['game_id'] ?? null,
            'buyer_user_id' => $payload['buyer_user_id'] ?? null,
            'schema_version' => $payload['schema_version'] ?? null,
        ]);
    }

    private function handleOrderRefunded(OutboxEvent $event): void
    {
        $payload = $event->payload;

        Log::info('outbox.order_refunded.delivered', [
            'outbox_event_id' => $event->id,
            'refund_id' => $payload['refund_id'] ?? null,
            'order_id' => $payload['order_id'] ?? null,
            'payment_id' => $payload['payment_id'] ?? null,
            'game_id' => $payload['game_id'] ?? null,
            'buyer_user_id' => $payload['buyer_user_id'] ?? null,
            'schema_version' => $payload['schema_version'] ?? null,
        ]);
    }

    private function handleWinnerPayoutRegistered(OutboxEvent $event): void
    {
        $payload = $event->payload;

        Log::info('outbox.winner_payout_registered.delivered', [
            'outbox_event_id' => $event->id,
            'winner_payout_id' => $payload['winner_payout_id'] ?? null,
            'game_winner_id' => $payload['game_winner_id'] ?? null,
            'game_id' => $payload['game_id'] ?? null,
            'winner_user_id' => $payload['winner_user_id'] ?? null,
            'schema_version' => $payload['schema_version'] ?? null,
        ]);
    }

    private function handleGameWinnerDeclared(OutboxEvent $event): void
    {
        $payload = $event->payload;

        Log::info('outbox.game_winner_declared.delivered', [
            'outbox_event_id' => $event->id,
            'game_winner_id' => $payload['game_winner_id'] ?? null,
            'game_id' => $payload['game_id'] ?? null,
            'game_draw_id' => $payload['game_draw_id'] ?? null,
            'game_number_id' => $payload['game_number_id'] ?? null,
            'winner_user_id' => $payload['winner_user_id'] ?? null,
            'schema_version' => $payload['schema_version'] ?? null,
        ]);
    }
}
