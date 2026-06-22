<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised by RebuildGameNumberCountersAction (Block 3.7) when the
 * recomputed projection does not exactly match the aggregation derived
 * from game_draws. The action rolls back so the old projection is
 * preserved.
 */
final class RebuildIntegrityViolation extends DomainException
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
