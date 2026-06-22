<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised by StartGameAction when the row's columns disagree with each
 * other in a way the state machine should make impossible:
 *
 *   - status = running   but started_at IS NULL
 *   - status = sales_closed but started_at IS NOT NULL
 *   - completed_at IS NOT NULL before any new start attempt
 *
 * Surface only safe context (game id, observed columns) — no buyer,
 * payment, internal path or stack details.
 */
final class GameLifecycleIntegrityViolation extends DomainException
{
    /**
     * @param  array<string, mixed>  $context  whitelist of safe columns
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
