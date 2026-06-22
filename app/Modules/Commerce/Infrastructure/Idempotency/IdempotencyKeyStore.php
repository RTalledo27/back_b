<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Idempotency;

use App\Modules\Commerce\Application\DTOs\CommandResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomic claim + completion primitives for idempotency_keys.
 *
 * Extracted so both IdempotentCommandExecutor (generic command path) and
 * SubmitPaymentEvidenceOrchestrator (storage-before-transaction path)
 * share a single implementation of the PostgreSQL-level race control.
 */
final class IdempotencyKeyStore
{
    /**
     * Atomic claim:
     *
     *  - INSERT ... ON CONFLICT DO NOTHING. rowCount == 1 -> Claimed.
     *  - On conflict, SELECT existing row and branch:
     *      * completed + matching payload      -> CompletedSamePayload
     *      * completed + different payload     -> PayloadMismatch
     *      * not completed, lock still fresh   -> InProgress
     *      * not completed, lock past timeout AND payload matches
     *        -> guarded UPDATE that refreshes locked_at without changing
     *           payload_sha256; success returns Claimed.
     *      * not completed, lock past timeout AND payload differs
     *        -> PayloadMismatch (a hijack attempt: an expired lock must NOT
     *           allow a different payload to take over the slot).
     */
    public function tryClaim(IdempotencyContext $context): IdempotencyClaim
    {
        $rowId = (string) Str::uuid7();
        $now = Carbon::now();
        $ttlHours = (int) config('commerce.idempotency.ttl_hours', 24);
        $expiresAt = $now->copy()->addHours($ttlHours);

        $insertedRows = DB::affectingStatement(
            'INSERT INTO idempotency_keys '
            .'(id, user_id, request_method, request_path, key, payload_sha256, locked_at, expires_at) '
            .'VALUES (?, ?, ?, ?, ?, ?, ?, ?) '
            .'ON CONFLICT (user_id, request_method, request_path, key) DO NOTHING',
            [
                $rowId,
                $context->userId,
                $context->method,
                $context->path,
                $context->key,
                $context->payloadSha256,
                $now,
                $expiresAt,
            ],
        );

        if ($insertedRows === 1) {
            return IdempotencyClaim::claimed($rowId);
        }

        $existing = DB::table('idempotency_keys')
            ->where('user_id', $context->userId)
            ->where('request_method', $context->method)
            ->where('request_path', $context->path)
            ->where('key', $context->key)
            ->first();

        if ($existing === null) {
            return $this->tryClaim($context);
        }

        if ($existing->completed_at !== null) {
            if (hash_equals($existing->payload_sha256, $context->payloadSha256)) {
                $payload = $existing->result_payload === null
                    ? []
                    : (array) json_decode((string) $existing->result_payload, true, 512, JSON_THROW_ON_ERROR);

                return IdempotencyClaim::completed($payload);
            }

            return IdempotencyClaim::payloadMismatch();
        }

        $timeoutSeconds = (int) config('commerce.idempotency.in_progress_timeout_seconds', 60);
        $lockedAt = Carbon::parse((string) $existing->locked_at);
        $abandonedAfter = $lockedAt->copy()->addSeconds($timeoutSeconds);

        if ($abandonedAfter->isFuture()) {
            return IdempotencyClaim::inProgress();
        }

        if (! hash_equals($existing->payload_sha256, $context->payloadSha256)) {
            return IdempotencyClaim::payloadMismatch();
        }

        $reclaimed = DB::update(
            'UPDATE idempotency_keys '
            .'SET locked_at = ?, expires_at = ? '
            .'WHERE id = ? AND completed_at IS NULL AND locked_at < ? '
            .'AND payload_sha256 = ?',
            [
                $now,
                $expiresAt,
                $existing->id,
                $abandonedAfter,
                $context->payloadSha256,
            ],
        );

        if ($reclaimed === 1) {
            return IdempotencyClaim::claimed((string) $existing->id);
        }

        return $this->tryClaim($context);
    }

    /**
     * Persist the command's result and mark the row complete. MUST run
     * inside the same DB transaction as the business writes so the result
     * is atomically tied to the outcome.
     */
    public function markCompleted(string $rowId, CommandResult $result): void
    {
        DB::update(
            'UPDATE idempotency_keys SET result_payload = ?::jsonb, completed_at = ? WHERE id = ?',
            [
                json_encode($result->toArray(), JSON_THROW_ON_ERROR),
                Carbon::now(),
                $rowId,
            ],
        );
    }

    /**
     * Drop a claim that did not finish (business threw before commit) so
     * the next retry sees no row and can re-execute immediately instead of
     * waiting for the in-progress timeout.
     */
    public function releaseAbandoned(string $rowId): void
    {
        DB::delete(
            'DELETE FROM idempotency_keys WHERE id = ? AND completed_at IS NULL',
            [$rowId],
        );
    }
}
