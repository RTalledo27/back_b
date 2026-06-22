<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only winner declaration. Exactly one per game (UNIQUE game_id).
 *
 * All foreign references use composite FKs against (id, game_id) so the
 * database itself rejects mixing entry / draw / number from a different
 * game. UNIQUE constraints on each FK target also block double-write.
 *
 * No updated_at: append-only. The Eloquent model blocks update/delete at
 * the ORM layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_winners', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('game_id')
                ->constrained('games')
                ->restrictOnDelete();

            $table->uuid('game_entry_id');
            $table->uuid('game_draw_id');
            $table->uuid('game_number_id');

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->integer('winning_hits');
            $table->timestampTz('won_at');

            $table->timestampTz('created_at')->useCurrent();

            $table->unique('game_id', 'game_winners_game_unique');
            $table->unique('game_entry_id', 'game_winners_entry_unique');
            $table->unique('game_draw_id', 'game_winners_draw_unique');

            $table->foreign(['game_entry_id', 'game_id'], 'game_winners_entry_game_composite_fk')
                ->references(['id', 'game_id'])
                ->on('game_entries')
                ->restrictOnDelete();

            $table->foreign(['game_draw_id', 'game_id'], 'game_winners_draw_game_composite_fk')
                ->references(['id', 'game_id'])
                ->on('game_draws')
                ->restrictOnDelete();

            $table->foreign(['game_number_id', 'game_id'], 'game_winners_number_game_composite_fk')
                ->references(['id', 'game_id'])
                ->on('game_numbers')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE game_winners ADD CONSTRAINT game_winners_winning_hits_check '
                .'CHECK (winning_hits > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_winners');
    }
};
