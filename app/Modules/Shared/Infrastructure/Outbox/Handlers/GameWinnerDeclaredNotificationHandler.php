<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Outbox\Handlers;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\Shared\Infrastructure\Outbox\OutboxEventDeferred;
use App\Notifications\Domain\GameWinnerDeclaredNotification;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class GameWinnerDeclaredNotificationHandler
{
    public function handle(string $outboxEventId, array $payload): void
    {
        $winnerUserId = (int) ($payload['winner_user_id'] ?? 0);
        $gameWinnerId = (string) ($payload['game_winner_id'] ?? '');
        $gameId = (string) ($payload['game_id'] ?? '');

        [$delivery, $wasJustCreated] = NotificationDelivery::claimForHandler(
            outboxEventId: $outboxEventId,
            eventType: 'game_winner_declared',
            recipientUserId: $winnerUserId,
            channel: NotificationDelivery::CHANNEL_MAIL,
        );

        if ($delivery->isFinalOrQueued()) {
            return;
        }

        if (! $wasJustCreated && $delivery->isPendingFresh()) {
            throw OutboxEventDeferred::forSeconds(NotificationDelivery::PENDING_FRESH_SECONDS);
        }

        $user = User::find($winnerUserId);
        if ($user === null) {
            throw new RuntimeException(
                "GameWinnerDeclaredNotificationHandler: user {$winnerUserId} not found for outbox event {$outboxEventId}."
            );
        }

        $gameWinner = GameWinner::find($gameWinnerId);
        if ($gameWinner === null) {
            Log::warning('outbox.game_winner_declared: game_winner not found', [
                'outbox_event_id' => $outboxEventId,
                'game_winner_id' => $gameWinnerId,
            ]);
            $delivery->markFailed('game_winner_not_found');

            return;
        }

        $user->notify(new GameWinnerDeclaredNotification($gameWinnerId, $gameId));

        $delivery->markQueued();
    }
}
