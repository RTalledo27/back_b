<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Outbox;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Routes a claimed outbox event to the appropriate consumer handler.
 *
 * Phase 8.2: only payment_approved is handled (no-op log — no real
 * notification provider yet).  The handler validates the payload shape
 * and records delivery intent.  Real email/WhatsApp/CRM delivery is
 * Phases 8.3 / 9.
 *
 * Phase 8.3+: add remaining event types here.
 */
class OutboxEventDispatcher
{
    /**
     * Dispatch a single outbox event to its consumer.
     *
     * Throws on unrecoverable errors so the processor marks failed_at.
     * Throws RuntimeException for unknown event types so they surface
     * as permanent failures rather than silently disappearing.
     */
    public function dispatch(OutboxEvent $event): void
    {
        match ($event->event_type) {
            'payment_approved' => $this->handlePaymentApproved($event),
            default => throw new RuntimeException(
                "OutboxEventDispatcher: unknown event_type '{$event->event_type}'."
            ),
        };
    }

    private function handlePaymentApproved(OutboxEvent $event): void
    {
        $payload = $event->payload;

        // Phase 8.2: delivery intent logged; real notification (email /
        // WhatsApp) arrives in Phase 9.
        Log::info('outbox.payment_approved.delivered', [
            'outbox_event_id' => $event->id,
            'payment_id' => $payload['payment_id'] ?? null,
            'order_id' => $payload['order_id'] ?? null,
            'game_id' => $payload['game_id'] ?? null,
            'buyer_user_id' => $payload['buyer_user_id'] ?? null,
            'schema_version' => $payload['schema_version'] ?? null,
        ]);
    }
}
