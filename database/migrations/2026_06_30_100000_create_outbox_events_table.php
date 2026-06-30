<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE outbox_events (
                id                UUID         NOT NULL,
                event_type        VARCHAR(120) NOT NULL,
                aggregate_type    VARCHAR(80)  NOT NULL,
                aggregate_id      UUID         NULL,
                deduplication_key VARCHAR(255) NULL,
                payload           JSONB        NOT NULL,
                available_at      TIMESTAMPTZ  NOT NULL,
                processed_at      TIMESTAMPTZ  NULL,
                failed_at         TIMESTAMPTZ  NULL,
                attempts          INT          NOT NULL DEFAULT 0,
                last_error        TEXT         NULL,
                locked_at         TIMESTAMPTZ  NULL,
                locked_by         VARCHAR(255) NULL,
                next_attempt_at   TIMESTAMPTZ  NULL,
                max_attempts      INT          NOT NULL DEFAULT 5,
                created_at        TIMESTAMPTZ  NOT NULL,

                CONSTRAINT outbox_events_pkey PRIMARY KEY (id),

                CONSTRAINT chk_event_type_not_empty
                    CHECK (trim(event_type) <> ''),

                CONSTRAINT chk_aggregate_type_not_empty
                    CHECK (trim(aggregate_type) <> ''),

                CONSTRAINT chk_payload_is_object
                    CHECK (jsonb_typeof(payload) = 'object'),

                CONSTRAINT chk_attempts_non_negative
                    CHECK (attempts >= 0),

                CONSTRAINT chk_max_attempts_positive
                    CHECK (max_attempts > 0),

                CONSTRAINT chk_attempts_le_max
                    CHECK (attempts <= max_attempts),

                CONSTRAINT chk_not_both_processed_and_failed
                    CHECK (NOT (processed_at IS NOT NULL AND failed_at IS NOT NULL))
            )
        SQL);

        // Working index: pending rows ordered by availability
        DB::statement(<<<'SQL'
            CREATE INDEX outbox_events_pending_idx
                ON outbox_events (available_at, id)
                WHERE processed_at IS NULL AND failed_at IS NULL
        SQL);

        // Partial unique index for deduplication of in-flight events only
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX outbox_events_dedup_unprocessed_idx
                ON outbox_events (deduplication_key)
                WHERE deduplication_key IS NOT NULL AND processed_at IS NULL
        SQL);

        // Lookup by aggregate for auditing and debugging
        DB::statement(<<<'SQL'
            CREATE INDEX outbox_events_aggregate_idx
                ON outbox_events (aggregate_type, aggregate_id)
                WHERE aggregate_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS outbox_events');
    }
};
