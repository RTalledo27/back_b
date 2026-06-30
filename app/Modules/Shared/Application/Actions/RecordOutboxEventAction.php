<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Actions;

use App\Modules\Shared\Application\DTOs\OutboxRecordResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Inserts one outbox event row inside the caller's active transaction.
 *
 * Uses INSERT ... ON CONFLICT DO NOTHING so that a duplicate
 * deduplication_key silences the insert without aborting the PostgreSQL
 * transaction.  Never catch UniqueConstraintViolationException here —
 * that would require the transaction to already be aborted.
 *
 * Must be called inside an active DB::transaction(); throws otherwise.
 */
final class RecordOutboxEventAction
{
    /**
     * @param  array<string, mixed>  $payload  Must be a JSON object (associative array).
     */
    public function execute(
        string $eventType,
        string $aggregateType,
        array $payload,
        ?string $aggregateId = null,
        ?string $deduplicationKey = null,
        int $maxAttempts = 5,
    ): OutboxRecordResult {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'RecordOutboxEventAction must be called inside an active database transaction.'
            );
        }

        if (trim($eventType) === '') {
            throw new LogicException('eventType must not be empty.');
        }

        if (trim($aggregateType) === '') {
            throw new LogicException('aggregateType must not be empty.');
        }

        $id = (string) Str::uuid7();
        $now = now();
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        // ON CONFLICT DO NOTHING: if the deduplication_key already exists
        // for an unprocessed row the insert is silently skipped.  The
        // transaction continues normally — no exception is thrown, no
        // rollback occurs.
        $affected = DB::affectingStatement(
            <<<'SQL'
            INSERT INTO outbox_events
                (id, event_type, aggregate_type, aggregate_id,
                 deduplication_key, payload, available_at,
                 attempts, max_attempts, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?::jsonb, ?, 0, ?, ?)
            ON CONFLICT (deduplication_key)
            WHERE deduplication_key IS NOT NULL AND processed_at IS NULL
            DO NOTHING
            SQL,
            [
                $id,
                $eventType,
                $aggregateType,
                $aggregateId,
                $deduplicationKey,
                $encodedPayload,
                $now,
                $maxAttempts,
                $now,
            ],
        );

        $inserted = $affected > 0;

        return new OutboxRecordResult(
            inserted: $inserted,
            outboxEventId: $inserted ? $id : null,
        );
    }
}
