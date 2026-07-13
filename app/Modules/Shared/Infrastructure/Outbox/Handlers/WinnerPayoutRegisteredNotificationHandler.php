<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Outbox\Handlers;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Notifications\Domain\WinnerPayoutRegisteredNotification;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class WinnerPayoutRegisteredNotificationHandler
{
    public function handle(string $outboxEventId, array $payload): void
    {
        $winnerUserId = (int) ($payload['winner_user_id'] ?? 0);
        $winnerPayoutId = (string) ($payload['winner_payout_id'] ?? '');
        $gameId = (string) ($payload['game_id'] ?? '');

        [$delivery, $wasJustCreated] = NotificationDelivery::claimForHandler(
            outboxEventId: $outboxEventId,
            eventType: 'winner_payout_registered',
            recipientUserId: $winnerUserId,
            channel: NotificationDelivery::CHANNEL_MAIL,
        );

        if ($delivery->isFinalOrQueued()) {
            return;
        }

        if (! $wasJustCreated && $delivery->isPendingFresh()) {
            return;
        }

        $user = User::find($winnerUserId);
        if ($user === null) {
            throw new RuntimeException(
                "WinnerPayoutRegisteredNotificationHandler: user {$winnerUserId} not found for outbox event {$outboxEventId}."
            );
        }

        $payout = WinnerPayout::find($winnerPayoutId);
        if ($payout === null) {
            Log::warning('outbox.winner_payout_registered: payout not found', [
                'outbox_event_id' => $outboxEventId,
                'winner_payout_id' => $winnerPayoutId,
            ]);
            $delivery->markFailed('winner_payout_not_found');

            return;
        }

        $user->notify(new WinnerPayoutRegisteredNotification($winnerPayoutId, $gameId));

        $delivery->markQueued();
    }
}
