<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class GameNotReadyForStart extends DomainException
{
    /**
     * @param  list<string>  $reasons  non-empty list of machine-readable reason codes
     */
    private function __construct(
        public readonly array $reasons,
    ) {
        parent::__construct(
            sprintf('Game is not ready to start: %s.', implode(', ', $reasons)),
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function withReasons(array $reasons): self
    {
        if ($reasons === []) {
            throw new \LogicException('GameNotReadyForStart must carry at least one reason.');
        }

        return new self(array_values($reasons));
    }
}
