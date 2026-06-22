<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Thrown by DrawGameNumberAction (Block 3.5) when the relationship
 * between GameNumber.status and GameEntry violates the invariants:
 *
 *   sold        ↔ exactly one Confirmed entry
 *   available   ↔ no entry at all
 *   reserved during Running → corruption (commerce should be drained)
 */
final class GameParticipationIntegrityViolation extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(string $message, public readonly array $context)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function withContext(string $message, array $context): self
    {
        return new self($message, $context);
    }
}
