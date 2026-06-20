<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('game_id')
                ->constrained('games')
                ->cascadeOnDelete();

            $table->string('type', 48);
            $table->jsonb('payload')->nullable();

            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['game_id', 'occurred_at']);
            $table->index('type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE game_events ADD CONSTRAINT game_events_type_check CHECK (type IN ("
                ."'game_created','game_published','sales_opened','number_reserved',"
                ."'reservation_expired','payment_submitted','payment_approved',"
                ."'payment_rejected','number_sold','sales_closed','scheduled_start_set',"
                ."'game_started','number_drawn','unowned_number_reached_threshold',"
                ."'winning_number_detected','winner_declared','winner_contacted',"
                ."'payout_scheduled','payout_paid','game_paused','game_resumed',"
                ."'game_completed','game_cancelled'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
