<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only canonical history of every number ever drawn for a game.
 *
 *  - Sequence is monotonic per game, enforced by UNIQUE(game_id, sequence).
 *  - game_number_id pertenence to the same game is enforced via composite
 *    FK -> game_numbers(id, game_id).
 *  - UNIQUE(id, game_id) lets game_winners and draw_commands enforce the
 *    same-aggregate rule on their own FKs.
 *  - No updated_at: rows are written once and never modified. The Eloquent
 *    model also blocks updates and deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_draws', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('game_id')
                ->constrained('games')
                ->restrictOnDelete();

            $table->uuid('game_number_id');

            $table->integer('sequence');
            $table->integer('drawn_number');
            $table->timestampTz('drawn_at');
            $table->string('strategy', 40);

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['game_id', 'sequence'], 'game_draws_game_sequence_unique');
            $table->unique(['id', 'game_id'], 'game_draws_id_game_id_unique');

            $table->index(['game_id', 'drawn_at'], 'game_draws_game_drawn_at_index');
            $table->index(['game_id', 'game_number_id'], 'game_draws_game_number_index');

            $table->foreign(['game_number_id', 'game_id'], 'game_draws_number_game_composite_fk')
                ->references(['id', 'game_id'])
                ->on('game_numbers')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE game_draws ADD CONSTRAINT game_draws_sequence_check CHECK (sequence > 0)');
            DB::statement('ALTER TABLE game_draws ADD CONSTRAINT game_draws_drawn_number_check CHECK (drawn_number >= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_draws');
    }
};
