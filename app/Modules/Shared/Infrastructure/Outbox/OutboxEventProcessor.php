<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Outbox;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Claims and processes a batch of pending outbox events.
 *
 * Algorithm (two-phase):
 *
 * Phase 1 — CLAIM (inside a transaction):
 *   SELECT ... FOR UPDATE SKIP LOCKED
 *   WHERE pending AND available AND (no lock OR stale lock)
 *   UPDATE locked_at, locked_by
 *   COMMIT
 *
 * Phase 2 — PROCESS (outside the claim transaction):
 *   For each claimed row, dispatch to OutboxEventDispatcher.
 *   On success  → processed_at = now(), clear lock.
 *   On retry    → attempts++, next_attempt_at, clear lock.
 *   On final    → failed_at = now(), clear lock.
 *
 * FOR UPDATE SKIP LOCKED prevents two workers from claiming the same row
 * during the claim transaction.  locked_at persists after COMMIT so that
 * no other worker picks up the same row in a subsequent poll.  Stale locks
 * (> 5 min) allow recovery from crashed workers.
 */
final class OutboxEventProcessor
{
    /** Stale lock threshold in seconds. */
    private const STALE_LOCK_SECONDS = 300;

    /** Backoff schedule in seconds per attempt (1-indexed). */
    private const BACKOFF_SECONDS = [1 => 30, 2 => 120, 3 => 600, 4 => 3600];

    public function __construct(private readonly OutboxEventDispatcher $dispatcher) {}

    /**
     * @return array{claimed: int, processed: int, failed: int}
     */
    public function processBatch(int $batchSize = 50, string $workerId = ''): array
    {
        if ($workerId === '') {
            $workerId = gethostname().':'.getmypid();
        }

        $claimed = $this->claimBatch($batchSize, $workerId);

        $processed = 0;
        $failed = 0;

        foreach ($claimed as $event) {
            $success = $this->processOne($event, $workerId);
            if ($success) {
                $processed++;
            } else {
                $failed++;
            }
        }

        return ['claimed' => count($claimed), 'processed' => $processed, 'failed' => $failed];
    }

    /**
     * @return array<int, OutboxEvent>
     */
    public function claimBatch(int $batchSize, string $workerId): array
    {
        $claimedIds = [];

        DB::transaction(function () use ($batchSize, $workerId, &$claimedIds): void {
            $rows = DB::select(
                <<<'SQL'
                SELECT id
                FROM outbox_events
                WHERE processed_at IS NULL
                  AND failed_at IS NULL
                  AND available_at <= NOW()
                  AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
                  AND (
                      locked_at IS NULL
                      OR locked_at < NOW() - INTERVAL '5 minutes'
                  )
                ORDER BY available_at ASC, id ASC
                LIMIT :batch
                FOR UPDATE SKIP LOCKED
                SQL,
                ['batch' => $batchSize],
            );

            if (empty($rows)) {
                return;
            }

            $claimedIds = array_column($rows, 'id');

            DB::table('outbox_events')
                ->whereIn('id', $claimedIds)
                ->update([
                    'locked_at' => now(),
                    'locked_by' => $workerId,
                ]);
        });

        if (empty($claimedIds)) {
            return [];
        }

        return OutboxEvent::query()
            ->whereIn('id', $claimedIds)
            ->get()
            ->all();
    }

    private function processOne(OutboxEvent $event, string $workerId): bool
    {
        try {
            $this->dispatcher->dispatch($event);

            DB::table('outbox_events')
                ->where('id', $event->id)
                ->update([
                    'processed_at' => now(),
                    'locked_at' => null,
                    'locked_by' => null,
                ]);

            return true;
        } catch (Throwable $e) {
            $newAttempts = $event->attempts + 1;
            $isFinal = $newAttempts >= $event->max_attempts;

            Log::warning('outbox.event.failed', [
                'outbox_event_id' => $event->id,
                'event_type' => $event->event_type,
                'attempts' => $newAttempts,
                'final' => $isFinal,
                'error' => $e->getMessage(),
            ]);

            $update = [
                'attempts' => $newAttempts,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                'locked_at' => null,
                'locked_by' => null,
            ];

            if ($isFinal) {
                $update['failed_at'] = now();
            } else {
                $update['next_attempt_at'] = now()->addSeconds(
                    self::BACKOFF_SECONDS[$newAttempts] ?? 3600
                );
            }

            DB::table('outbox_events')
                ->where('id', $event->id)
                ->update($update);

            return false;
        }
    }
}
