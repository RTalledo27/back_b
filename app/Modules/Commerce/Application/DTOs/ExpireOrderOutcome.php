<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

/**
 * Explicit outcomes for an ExpireOrderAction invocation. Only `Expired`
 * represents a fresh transition that should fire after-commit events.
 * Every other value is a safe no-op (idempotent replay or race-lost).
 */
enum ExpireOrderOutcome: string
{
    case Expired = 'expired';
    case AlreadyExpired = 'already_expired';
    case SkippedStateChanged = 'skipped_state_changed';
    case NotDue = 'not_due';

    public function wasTransitionApplied(): bool
    {
        return $this === self::Expired;
    }
}
