<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class InvalidGameEngineConfiguration extends DomainException
{
    public static function invalidInterval(string $gameId, int $interval, int $min, int $max): self
    {
        return new self(
            "Game {$gameId} has an invalid draw_interval_seconds ({$interval}). "
            ."Must be between {$min} and {$max} when auto_draw_enabled is true."
        );
    }

    public static function unsupportedCatchUpPolicy(string $policy): self
    {
        return new self(
            "Unsupported engine catch-up policy '{$policy}'. Only 'skip_to_next' is supported."
        );
    }
}
