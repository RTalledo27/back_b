<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Pause and Resume are engine-automation operations. They require
 * `auto_draw_enabled = true` because Resume must schedule a future tick;
 * a manual game must not end up with next_draw_at set.
 */
final class GameEngineAutomationInactive extends DomainException
{
    public static function forGame(string $gameId): self
    {
        return new self(
            "Game {$gameId} does not have automatic draw scheduling active. "
            .'Pause and Resume require auto_draw_enabled = true.'
        );
    }
}
