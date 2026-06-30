<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use App\Modules\Shared\Application\Actions\RecordOutboxEventAction;
use App\Modules\Shared\Application\DTOs\OutboxRecordResult;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class RecordOutboxEventActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function action(): RecordOutboxEventAction
    {
        return $this->app->make(RecordOutboxEventAction::class);
    }

    public function test_throws_when_called_outside_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('active database transaction');

        $this->action()->execute(
            eventType: 'payment_approved',
            aggregateType: 'payment',
            payload: ['schema_version' => 1],
        );
    }

    public function test_inserts_row_inside_transaction(): void
    {
        $paymentId = (string) Str::uuid7();

        $result = DB::transaction(fn () => $this->action()->execute(
            eventType: 'payment_approved',
            aggregateType: 'payment',
            payload: ['schema_version' => 1, 'payment_id' => $paymentId],
            aggregateId: $paymentId,
            deduplicationKey: 'payment_approved:'.$paymentId,
        ));

        $this->assertInstanceOf(OutboxRecordResult::class, $result);
        $this->assertTrue($result->inserted);
        $this->assertNotNull($result->outboxEventId);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'payment_approved',
            'aggregate_type' => 'payment',
            'aggregate_id' => $paymentId,
            'deduplication_key' => 'payment_approved:'.$paymentId,
        ]);
    }

    public function test_on_conflict_do_nothing_does_not_abort_transaction(): void
    {
        $key = 'payment_approved:'.(string) Str::uuid7();

        DB::transaction(function () use ($key): void {
            // First insert
            $r1 = $this->action()->execute(
                eventType: 'payment_approved',
                aggregateType: 'payment',
                payload: ['schema_version' => 1],
                deduplicationKey: $key,
            );
            $this->assertTrue($r1->inserted);

            // Second insert with same deduplication_key — must NOT abort the
            // transaction. ON CONFLICT DO NOTHING silences the duplicate.
            $r2 = $this->action()->execute(
                eventType: 'payment_approved',
                aggregateType: 'payment',
                payload: ['schema_version' => 1],
                deduplicationKey: $key,
            );
            $this->assertFalse($r2->inserted);
            $this->assertNull($r2->outboxEventId);

            // A subsequent write inside the same transaction must succeed,
            // proving the transaction was not aborted.
            DB::table('outbox_events')->where('deduplication_key', $key)->exists();
        });

        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_rollback_removes_outbox_row(): void
    {
        try {
            DB::transaction(function (): void {
                $this->action()->execute(
                    eventType: 'payment_approved',
                    aggregateType: 'payment',
                    payload: ['schema_version' => 1],
                );
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_returns_inserted_false_when_duplicate_key_conflict(): void
    {
        $key = 'payment_approved:'.(string) Str::uuid7();

        DB::transaction(fn () => $this->action()->execute(
            eventType: 'payment_approved',
            aggregateType: 'payment',
            payload: ['schema_version' => 1],
            deduplicationKey: $key,
        ));

        $result = DB::transaction(fn () => $this->action()->execute(
            eventType: 'payment_approved',
            aggregateType: 'payment',
            payload: ['schema_version' => 1],
            deduplicationKey: $key,
        ));

        $this->assertFalse($result->inserted);
        $this->assertNull($result->outboxEventId);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_empty_event_type_throws_logic_exception(): void
    {
        $this->expectException(LogicException::class);

        DB::transaction(fn () => $this->action()->execute(
            eventType: '  ',
            aggregateType: 'payment',
            payload: [],
        ));
    }

    public function test_empty_aggregate_type_throws_logic_exception(): void
    {
        $this->expectException(LogicException::class);

        DB::transaction(fn () => $this->action()->execute(
            eventType: 'payment_approved',
            aggregateType: '',
            payload: [],
        ));
    }
}
