<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuildable projection over game_draws. game_draws is the source of
 * truth — this table is a cache for fast queries on (hits_count,
 * last_draw_sequence) per game_number. RebuildGameNumberCountersAction
 * (Block 3.7) can regenerate it from scratch at any time.
 *
 * Same-game integrity is enforced via composite FK to game_numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('game_id')
                ->constrained('games')
                ->cascadeOnDelete();

            $table->uuid('game_number_id');

            $table->integer('hits_count')->default(0);
            $table->integer('last_draw_sequence')->nullable();

            $table->timestampsTz();

            $table->unique(['game_id', 'game_number_id'], 'game_number_counters_game_number_unique');

            $table->foreign(['game_number_id', 'game_id'], 'game_number_counters_number_game_composite_fk')
                ->references(['id', 'game_id'])
                ->on('game_numbers')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE game_number_counters ADD CONSTRAINT game_number_counters_hits_count_check '
                .'CHECK (hits_count >= 0)'
            );
            DB::statement(
                'ALTER TABLE game_number_counters ADD CONSTRAINT game_number_counters_last_seq_check '
                .'CHECK (last_draw_sequence IS NULL OR last_draw_sequence > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_number_counters');
    }
};
