<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use App\Modules\Shared\Infrastructure\Outbox\OutboxEventDispatcher;
use App\Modules\Shared\Infrastructure\Outbox\OutboxEventProcessor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class OutboxEventProcessorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function insertPendingEvent(array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('outbox_events')->insert(array_merge([
            'id' => $id,
            'event_type' => 'payment_approved',
            'aggregate_type' => 'payment',
            'aggregate_id' => null,
            'deduplication_key' => null,
            'payload' => json_encode(['schema_version' => 1]),
            'available_at' => now()->subSecond(),
            'attempts' => 0,
            'max_attempts' => 5,
            'created_at' => now(),
        ], $overrides));

        return $id;
    }

    private function makeProcessor(?OutboxEventDispatcher $dispatcher = null): OutboxEventProcessor
    {
        if ($dispatcher === null) {
            $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        }

        return new OutboxEventProcessor($dispatcher);
    }

    public function test_processes_pending_event_and_marks_processed(): void
    {
        $id = $this->insertPendingEvent();

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch');

        $result = $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $this->assertSame(['claimed' => 1, 'processed' => 1, 'failed' => 0], $result);

        $row = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertNotNull($row->processed_at);
        $this->assertNull($row->locked_at);
        $this->assertNull($row->locked_by);
    }

    public function test_skips_event_with_fresh_lock(): void
    {
        $this->insertPendingEvent(['locked_at' => now(), 'locked_by' => 'other-worker']);

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $result = $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $this->assertSame(['claimed' => 0, 'processed' => 0, 'failed' => 0], $result);
    }

    public function test_reclaims_event_with_stale_lock(): void
    {
        $staleLocked = now()->subMinutes(6);
        $id = $this->insertPendingEvent([
            'locked_at' => $staleLocked,
            'locked_by' => 'dead-worker',
        ]);

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch');

        $result = $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $this->assertSame(['claimed' => 1, 'processed' => 1, 'failed' => 0], $result);

        $row = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertNotNull($row->processed_at);
    }

    public function test_clears_lock_on_success(): void
    {
        $id = $this->insertPendingEvent();

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $row = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertNull($row->locked_at);
        $this->assertNull($row->locked_by);
        $this->assertNotNull($row->processed_at);
    }

    public function test_clears_lock_on_retryable_failure(): void
    {
        $id = $this->insertPendingEvent(['max_attempts' => 5]);

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('transient error'));

        $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $row = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertNull($row->locked_at);
        $this->assertNull($row->locked_by);
        $this->assertNull($row->processed_at);
        $this->assertNull($row->failed_at);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->next_attempt_at);
    }

    public function test_marks_failed_at_on_final_attempt(): void
    {
        // max_attempts = 1 so the first failure is also the final
        $id = $this->insertPendingEvent(['max_attempts' => 1]);

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('permanent error'));

        $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $row = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertNotNull($row->failed_at);
        $this->assertNull($row->processed_at);
        $this->assertNull($row->locked_at);
        $this->assertNull($row->locked_by);
        $this->assertSame(1, (int) $row->attempts);
    }

    public function test_clears_lock_on_final_failure(): void
    {
        $id = $this->insertPendingEvent(['max_attempts' => 2, 'attempts' => 1]);

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('error'));

        $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $row = DB::table('outbox_events')->where('id', $id)->first();
        $this->assertNull($row->locked_at);
        $this->assertNull($row->locked_by);
        $this->assertNotNull($row->failed_at);
    }

    public function test_skips_processed_events(): void
    {
        $this->insertPendingEvent(['processed_at' => now()]);

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $result = $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $this->assertSame(['claimed' => 0, 'processed' => 0, 'failed' => 0], $result);
    }

    public function test_skips_failed_events(): void
    {
        $this->insertPendingEvent(['failed_at' => now()]);

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $result = $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $this->assertSame(['claimed' => 0, 'processed' => 0, 'failed' => 0], $result);
    }

    public function test_skips_event_not_yet_available(): void
    {
        $this->insertPendingEvent(['available_at' => now()->addMinutes(5)]);

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $result = $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $this->assertSame(['claimed' => 0, 'processed' => 0, 'failed' => 0], $result);
    }

    public function test_skips_event_in_backoff_period(): void
    {
        $this->insertPendingEvent([
            'attempts' => 1,
            'next_attempt_at' => now()->addMinutes(10),
        ]);

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $result = $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $this->assertSame(['claimed' => 0, 'processed' => 0, 'failed' => 0], $result);
    }

    public function test_processes_multiple_events_in_batch(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->insertPendingEvent();
        }

        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->expects($this->exactly(3))->method('dispatch');

        $result = $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $this->assertSame(['claimed' => 3, 'processed' => 3, 'failed' => 0], $result);
    }

    public function test_returns_summary_with_mixed_outcomes(): void
    {
        // 1 pending → success, 1 pending → failure
        $this->insertPendingEvent(['deduplication_key' => 'key-ok']);
        $this->insertPendingEvent(['deduplication_key' => null, 'max_attempts' => 1]);

        $callCount = 0;
        $dispatcher = $this->createMock(OutboxEventDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(function () use (&$callCount): void {
            $callCount++;
            if ($callCount === 2) {
                throw new RuntimeException('fail');
            }
        });

        $result = $this->makeProcessor($dispatcher)->processBatch(batchSize: 10);

        $this->assertSame(2, $result['claimed']);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['failed']);
    }
}
