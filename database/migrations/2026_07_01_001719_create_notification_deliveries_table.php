<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE notification_deliveries (
                id                  UUID         NOT NULL,
                outbox_event_id     UUID         NOT NULL,
                event_type          VARCHAR(120) NOT NULL,
                recipient_user_id   BIGINT       NOT NULL,
                channel             VARCHAR(32)  NOT NULL,
                deduplication_key   VARCHAR(255) NOT NULL,
                status              VARCHAR(32)  NOT NULL DEFAULT 'pending',
                queued_at           TIMESTAMPTZ  NULL,
                sent_at             TIMESTAMPTZ  NULL,
                failed_at           TIMESTAMPTZ  NULL,
                attempts            INT          NOT NULL DEFAULT 0,
                last_error          TEXT         NULL,
                created_at          TIMESTAMPTZ  NOT NULL,
                updated_at          TIMESTAMPTZ  NULL,

                CONSTRAINT notification_deliveries_pkey PRIMARY KEY (id),

                CONSTRAINT chk_nd_event_type_not_empty
                    CHECK (trim(event_type) <> ''),

                CONSTRAINT chk_nd_channel_not_empty
                    CHECK (trim(channel) <> ''),

                CONSTRAINT chk_nd_dedup_key_not_empty
                    CHECK (trim(deduplication_key) <> ''),

                CONSTRAINT chk_nd_status_valid
                    CHECK (status IN ('pending', 'queued', 'sent', 'failed')),

                CONSTRAINT chk_nd_attempts_non_negative
                    CHECK (attempts >= 0),

                CONSTRAINT notification_deliveries_dedup_key UNIQUE (deduplication_key)
            )
        SQL);

        DB::statement('CREATE INDEX notification_deliveries_outbox_event_idx ON notification_deliveries (outbox_event_id)');
        DB::statement('CREATE INDEX notification_deliveries_recipient_idx ON notification_deliveries (recipient_user_id)');
        DB::statement('CREATE INDEX notification_deliveries_status_idx ON notification_deliveries (status)');
        DB::statement('CREATE INDEX notification_deliveries_channel_idx ON notification_deliveries (channel)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS notification_deliveries');
    }
};
