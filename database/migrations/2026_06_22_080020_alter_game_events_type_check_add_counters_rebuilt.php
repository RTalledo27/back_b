<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3.7 adds `counters_rebuilt` to GameEventType. The original CHECK
 * constraint on `game_events.type` (defined in migration
 * 2026_06_20_093156 from Phase 1) lists every accepted value verbatim
 * and would reject the new audit row. Replace it without touching the
 * closed Phase 1 migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE game_events DROP CONSTRAINT IF EXISTS game_events_type_check');
        DB::statement(
            'ALTER TABLE game_events ADD CONSTRAINT game_events_type_check CHECK (type IN ('
            ."'game_created','game_published','sales_opened','number_reserved',"
            ."'reservation_expired','payment_submitted','payment_approved',"
            ."'payment_rejected','number_sold','sales_closed','scheduled_start_set',"
            ."'game_started','number_drawn','unowned_number_reached_threshold',"
            ."'winning_number_detected','winner_declared','winner_contacted',"
            ."'payout_scheduled','payout_paid','game_paused','game_resumed',"
            ."'game_completed','game_cancelled','counters_rebuilt'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE game_events DROP CONSTRAINT IF EXISTS game_events_type_check');
        DB::statement(
            'ALTER TABLE game_events ADD CONSTRAINT game_events_type_check CHECK (type IN ('
            ."'game_created','game_published','sales_opened','number_reserved',"
            ."'reservation_expired','payment_submitted','payment_approved',"
            ."'payment_rejected','number_sold','sales_closed','scheduled_start_set',"
            ."'game_started','number_drawn','unowned_number_reached_threshold',"
            ."'winning_number_detected','winner_declared','winner_contacted',"
            ."'payout_scheduled','payout_paid','game_paused','game_resumed',"
            ."'game_completed','game_cancelled'))"
        );
    }
};
