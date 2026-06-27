<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class PayoutNotProcessable extends DomainException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function gameNotCompleted(string $gameId, string $currentStatus): self
    {
        return new self(
            "Game {$gameId} is in status '{$currentStatus}', expected 'completed'.",
            'game_not_completed',
        );
    }

    public static function winnerNotFound(string $gameId): self
    {
        return new self(
            "No winner found for game {$gameId}.",
            'winner_not_found',
        );
    }

    public static function prizeAmountInvalid(string $gameId, int $prizeCents): self
    {
        return new self(
            "Game {$gameId} has invalid prize amount: {$prizeCents}.",
            'invalid_prize_amount',
        );
    }
}
