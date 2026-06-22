<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Idempotency;

/**
 * Explicit outcomes returned by IdempotentCommandExecutor's atomic claim.
 * Each value drives a distinct branch in the executor — there is no
 * implicit "unknown" state.
 */
enum IdempotencyClaimResult: string
{
    case Claimed = 'claimed';
    case CompletedSamePayload = 'completed_same_payload';
    case PayloadMismatch = 'payload_mismatch';
    case InProgress = 'in_progress';
}
