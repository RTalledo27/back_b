<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Idempotency;

use App\Modules\Commerce\Application\DTOs\CommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Exceptions\IdempotencyInProgress;
use App\Modules\Commerce\Domain\Exceptions\IdempotencyKeyMismatch;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generic idempotent executor for Commerce Actions whose entire side
 * effect lives in PostgreSQL.
 *
 * The optional `afterCommit` closure runs exclusively on the Claimed
 * branch — after the business transaction commits successfully — and
 * never on CompletedSamePayload replays. Exceptions raised inside
 * `afterCommit` are reported via report() and do NOT roll back the
 * committed business result, nor release the idempotency key.
 *
 * Callers that want at-most-once domain events should branch inside
 * `afterCommit` on the result's `wasTransitionApplied` flag so that a
 * Claimed-but-no-transition outcome (e.g. a fresh key against an
 * already-finalised payment) also skips dispatch.
 */
final class IdempotentCommandExecutor
{
    public function __construct(private readonly IdempotencyKeyStore $keys) {}

    public function execute(
        IdempotencyContext $context,
        Closure $command,
        Closure $hydrate,
        ?Closure $afterCommit = null,
    ): CommandResult {
        $claim = $this->keys->tryClaim($context);

        return match ($claim->result) {
            IdempotencyClaimResult::Claimed => $this->runClaimed(
                rowId: (string) $claim->rowId,
                command: $command,
                afterCommit: $afterCommit,
            ),
            IdempotencyClaimResult::CompletedSamePayload => $hydrate((array) $claim->resultPayload),
            IdempotencyClaimResult::PayloadMismatch => throw IdempotencyKeyMismatch::forKey($context->key),
            IdempotencyClaimResult::InProgress => throw IdempotencyInProgress::forKey($context->key),
        };
    }

    private function runClaimed(string $rowId, Closure $command, ?Closure $afterCommit): CommandResult
    {
        try {
            $result = DB::transaction(function () use ($rowId, $command): CommandResult {
                /** @var CommandResult $result */
                $result = $command();

                $this->keys->markCompleted($rowId, $result);

                return $result;
            });
        } catch (Throwable $e) {
            $this->keys->releaseAbandoned($rowId);

            throw $e;
        }

        // Past this point the business commit is durable. A failing
        // afterCommit listener must NOT trigger compensation.
        if ($afterCommit !== null) {
            try {
                $afterCommit($result);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $result;
    }

    /**
     * Reflection bridge retained for legacy concurrency tests that need
     * to inspect the raw claim result. Production callers should use
     * IdempotencyKeyStore::tryClaim directly.
     */
    public function tryClaimForTest(IdempotencyContext $context): IdempotencyClaim
    {
        return $this->keys->tryClaim($context);
    }
}
