<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use App\Models\NotificationDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies DB-level constraints, model methods, and idempotency semantics
 * for the notification_deliveries table (Phase 9.2).
 */
final class NotificationDeliveriesConstraintsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function validRow(array $overrides = []): array
    {
        $outboxEventId = (string) Str::uuid7();
        $recipientUserId = 1;
        $channel = 'mail';

        return array_merge([
            'id' => (string) Str::uuid7(),
            'outbox_event_id' => $outboxEventId,
            'event_type' => 'payment_approved',
            'recipient_user_id' => $recipientUserId,
            'channel' => $channel,
            'deduplication_key' => "{$outboxEventId}:{$recipientUserId}:{$channel}",
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
        ], $overrides);
    }

    private function insert(array $row): void
    {
        DB::table('notification_deliveries')->insert($row);
    }

    // ── Table existence ───────────────────────────────────────────────────────

    public function test_table_exists(): void
    {
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    // ── CHECK constraints ─────────────────────────────────────────────────────

    public function test_valid_row_inserts_without_error(): void
    {
        $this->insert($this->validRow());

        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_blank_event_type_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow(['event_type' => '   ']));
    }

    public function test_blank_channel_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow(['channel' => '']));
    }

    public function test_blank_dedup_key_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow(['deduplication_key' => '']));
    }

    public function test_invalid_status_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow(['status' => 'processing']));
    }

    public function test_negative_attempts_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow(['attempts' => -1]));
    }

    // ── UNIQUE(deduplication_key) ─────────────────────────────────────────────

    public function test_unique_dedup_key_prevents_duplicate_insert(): void
    {
        $row = $this->validRow();
        $this->insert($row);

        $this->expectException(QueryException::class);

        $this->insert(array_merge($row, ['id' => (string) Str::uuid7()]));
    }

    public function test_on_conflict_do_nothing_silences_duplicate(): void
    {
        $row = $this->validRow();
        $this->insert($row);

        DB::statement(<<<'SQL'
            INSERT INTO notification_deliveries
                (id, outbox_event_id, event_type, recipient_user_id, channel,
                 deduplication_key, status, attempts, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', 0, ?)
            ON CONFLICT (deduplication_key) DO NOTHING
        SQL, [
            (string) Str::uuid7(),
            $row['outbox_event_id'],
            $row['event_type'],
            $row['recipient_user_id'],
            $row['channel'],
            $row['deduplication_key'],
            now(),
        ]);

        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    // ── NotificationDelivery::claim() ─────────────────────────────────────────

    public function test_claim_creates_pending_row(): void
    {
        $outboxEventId = (string) Str::uuid7();

        $delivery = NotificationDelivery::claim(
            outboxEventId: $outboxEventId,
            eventType: 'payment_approved',
            recipientUserId: 1,
            channel: 'mail',
        );

        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $delivery->status);
        $this->assertSame("{$outboxEventId}:1:mail", $delivery->deduplication_key);
    }

    public function test_claim_returns_existing_row_on_duplicate(): void
    {
        $outboxEventId = (string) Str::uuid7();

        $first = NotificationDelivery::claim(
            outboxEventId: $outboxEventId,
            eventType: 'payment_approved',
            recipientUserId: 1,
            channel: 'mail',
        );

        $second = NotificationDelivery::claim(
            outboxEventId: $outboxEventId,
            eventType: 'payment_approved',
            recipientUserId: 1,
            channel: 'mail',
        );

        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertSame($first->id, $second->id);
    }

    // ── Status query methods ──────────────────────────────────────────────────

    public function test_is_final_or_queued_true_for_queued(): void
    {
        $this->insert($this->validRow(['status' => 'queued']));

        $delivery = NotificationDelivery::first();

        $this->assertTrue($delivery->isFinalOrQueued());
    }

    public function test_is_final_or_queued_true_for_sent(): void
    {
        $this->insert($this->validRow(['status' => 'sent']));

        $delivery = NotificationDelivery::first();

        $this->assertTrue($delivery->isFinalOrQueued());
    }

    public function test_is_final_or_queued_false_for_pending(): void
    {
        $this->insert($this->validRow(['status' => 'pending']));

        $delivery = NotificationDelivery::first();

        $this->assertFalse($delivery->isFinalOrQueued());
    }

    public function test_is_final_or_queued_false_for_failed(): void
    {
        $this->insert($this->validRow(['status' => 'failed']));

        $delivery = NotificationDelivery::first();

        $this->assertFalse($delivery->isFinalOrQueued());
    }

    public function test_is_pending_fresh_true_for_recent_pending(): void
    {
        $this->insert($this->validRow(['status' => 'pending', 'updated_at' => now()]));

        $delivery = NotificationDelivery::first();

        $this->assertTrue($delivery->isPendingFresh());
    }

    public function test_is_pending_fresh_false_for_stale_pending(): void
    {
        $stale = now()->subSeconds(NotificationDelivery::PENDING_FRESH_SECONDS + 10);
        $this->insert($this->validRow(['status' => 'pending', 'updated_at' => $stale]));

        $delivery = NotificationDelivery::first();

        $this->assertFalse($delivery->isPendingFresh());
    }

    public function test_is_retryable_pending_true_for_stale_pending_within_attempts(): void
    {
        $stale = now()->subSeconds(NotificationDelivery::PENDING_FRESH_SECONDS + 10);
        $this->insert($this->validRow(['status' => 'pending', 'updated_at' => $stale, 'attempts' => 0]));

        $delivery = NotificationDelivery::first();

        $this->assertTrue($delivery->isRetryablePending());
    }

    public function test_is_retryable_pending_false_for_fresh_pending(): void
    {
        $this->insert($this->validRow(['status' => 'pending', 'updated_at' => now(), 'attempts' => 0]));

        $delivery = NotificationDelivery::first();

        $this->assertFalse($delivery->isRetryablePending());
    }

    public function test_is_retryable_failed_true_within_attempts(): void
    {
        $this->insert($this->validRow(['status' => 'failed', 'attempts' => 1]));

        $delivery = NotificationDelivery::first();

        $this->assertTrue($delivery->isRetryableFailed());
    }

    public function test_is_retryable_failed_false_at_max_attempts(): void
    {
        $this->insert($this->validRow(['status' => 'failed', 'attempts' => NotificationDelivery::MAX_ATTEMPTS]));

        $delivery = NotificationDelivery::first();

        $this->assertFalse($delivery->isRetryableFailed());
    }

    // ── Status transitions ────────────────────────────────────────────────────

    public function test_mark_queued_sets_status_and_queued_at(): void
    {
        $this->insert($this->validRow());

        $delivery = NotificationDelivery::first();
        $delivery->markQueued();

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertNotNull($delivery->queued_at);
        $this->assertNotNull($delivery->updated_at);
    }

    public function test_mark_sent_sets_status_and_sent_at(): void
    {
        $this->insert($this->validRow(['status' => 'queued']));

        $delivery = NotificationDelivery::first();
        $delivery->markSent();

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertNotNull($delivery->sent_at);
    }

    public function test_mark_failed_increments_attempts_and_sets_last_error(): void
    {
        $this->insert($this->validRow(['attempts' => 0]));

        $delivery = NotificationDelivery::first();
        $delivery->markFailed('payment_not_in_approved_state');

        $delivery->refresh();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('payment_not_in_approved_state', $delivery->last_error);
        $this->assertNotNull($delivery->failed_at);
    }
}
