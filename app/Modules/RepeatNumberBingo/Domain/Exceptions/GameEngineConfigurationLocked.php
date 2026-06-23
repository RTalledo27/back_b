<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class GameEngineConfigurationLocked extends DomainException
{
    public static function forField(string $gameId, string $field, string $status): self
    {
        return new self(
            "Field '{$field}' cannot be changed on game {$gameId} while status is '{$status}'."
        );
    }
}
