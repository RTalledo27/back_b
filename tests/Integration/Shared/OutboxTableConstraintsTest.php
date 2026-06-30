<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies the DB-level CHECK constraints and partial unique index
 * defined in the outbox_events migration.  Each test triggers a
 * constraint via raw SQL so it bypasses PHP-side validation.
 */
final class OutboxTableConstraintsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function validRow(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid7(),
            'event_type' => 'payment_approved',
            'aggregate_type' => 'payment',
            'aggregate_id' => null,
            'deduplication_key' => null,
            'payload' => '{"schema_version":1}',
            'available_at' => now(),
            'attempts' => 0,
            'max_attempts' => 5,
            'created_at' => now(),
        ], $overrides);
    }

    private function insert(array $row): void
    {
        DB::table('outbox_events')->insert($row);
    }

    public function test_valid_row_inserts_without_error(): void
    {
        $this->insert($this->validRow());

        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_blank_event_type_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow(['event_type' => '   ']));
    }

    public function test_blank_aggregate_type_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow(['aggregate_type' => '']));
    }

    public function test_non_object_payload_rejected(): void
    {
        $this->expectException(QueryException::class);

        // JSON array is not a JSON object
        $this->insert($this->validRow(['payload' => '[1,2,3]']));
    }

    public function test_negative_attempts_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow(['attempts' => -1]));
    }

    public function test_zero_max_attempts_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow(['max_attempts' => 0]));
    }

    public function test_attempts_greater_than_max_attempts_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow(['attempts' => 6, 'max_attempts' => 5]));
    }

    public function test_both_processed_at_and_failed_at_rejected(): void
    {
        $this->expectException(QueryException::class);

        $this->insert($this->validRow([
            'processed_at' => now(),
            'failed_at' => now(),
        ]));
    }

    public function test_deduplication_key_unique_for_unprocessed_rows(): void
    {
        $key = 'payment_approved:'.Str::uuid7();

        $this->insert($this->validRow(['deduplication_key' => $key]));

        $this->expectException(QueryException::class);

        $this->insert($this->validRow([
            'id' => (string) Str::uuid7(),
            'deduplication_key' => $key,
        ]));
    }

    public function test_deduplication_key_allows_duplicate_when_first_is_processed(): void
    {
        $key = 'payment_approved:'.Str::uuid7();

        $this->insert($this->validRow([
            'deduplication_key' => $key,
            'processed_at' => now(),
        ]));

        // Second insert with same key but first row is processed — partial
        // index does not cover processed rows, so no conflict.
        $this->insert($this->validRow([
            'id' => (string) Str::uuid7(),
            'deduplication_key' => $key,
        ]));

        $this->assertDatabaseCount('outbox_events', 2);
    }

    public function test_null_deduplication_key_allows_multiple_rows(): void
    {
        $this->insert($this->validRow(['deduplication_key' => null]));
        $this->insert($this->validRow(['id' => (string) Str::uuid7(), 'deduplication_key' => null]));

        $this->assertDatabaseCount('outbox_events', 2);
    }
}
