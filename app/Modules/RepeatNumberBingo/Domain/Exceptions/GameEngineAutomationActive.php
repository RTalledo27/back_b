<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class GameEngineAutomationActive extends DomainException
{
    public static function forGame(string $gameId): self
    {
        return new self(
            "Game {$gameId} has automatic draw scheduling active. "
            .'Set auto_draw_enabled = false before performing manual draws.'
        );
    }
}
