<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class GamePrizeFundingNotProcessable extends DomainException
{
    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function status(string $gameId, string $status): self
    {
        return new self(
            "Prize funding for game {$gameId} cannot be processed from status '{$status}'.",
            'invalid_funding_status',
        );
    }

    public static function gameStatus(string $gameId, string $status): self
    {
        return new self(
            "Prize funding for game {$gameId} cannot be changed while the game is '{$status}'.",
            'game_not_fundable',
        );
    }

    public static function notReady(string $gameId, string $status): self
    {
        return new self(
            "Game {$gameId} cannot start because prize funding is '{$status}'.",
            'prize_funding_not_ready',
        );
    }

    public static function amountMismatch(string $gameId): self
    {
        return new self(
            "Prize funding for game {$gameId} does not match the announced prize.",
            'prize_funding_mismatch',
        );
    }
}
