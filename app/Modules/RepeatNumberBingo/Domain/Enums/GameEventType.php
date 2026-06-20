<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Enums;

/**
 * Inmutable timeline of relevant facts for a game. All 23 types from the
 * domain spec are declared upfront so the DB CHECK constraint never needs
 * to be relaxed when later phases start emitting them.
 */
enum GameEventType: string
{
    case GameCreated = 'game_created';
    case GamePublished = 'game_published';
    case SalesOpened = 'sales_opened';
    case NumberReserved = 'number_reserved';
    case ReservationExpired = 'reservation_expired';
    case PaymentSubmitted = 'payment_submitted';
    case PaymentApproved = 'payment_approved';
    case PaymentRejected = 'payment_rejected';
    case NumberSold = 'number_sold';
    case SalesClosed = 'sales_closed';
    case ScheduledStartSet = 'scheduled_start_set';
    case GameStarted = 'game_started';
    case NumberDrawn = 'number_drawn';
    case UnownedNumberReachedThreshold = 'unowned_number_reached_threshold';
    case WinningNumberDetected = 'winning_number_detected';
    case WinnerDeclared = 'winner_declared';
    case WinnerContacted = 'winner_contacted';
    case PayoutScheduled = 'payout_scheduled';
    case PayoutPaid = 'payout_paid';
    case GamePaused = 'game_paused';
    case GameResumed = 'game_resumed';
    case GameCompleted = 'game_completed';
    case GameCancelled = 'game_cancelled';
}
