<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 hardening on game_draws:
 *
 *  1. UNIQUE(id, game_id, game_number_id) — required so game_winners can
 *     FK on (game_draw_id, game_id, game_number_id) and force the winning
 *     draw to share both game and number with the winner.
 *
 *  2. Composite FK (game_number_id, game_id, drawn_number) ->
 *     game_numbers(id, game_id, number) — forces drawn_number to equal the
 *     `number` column of the referenced game_number. Removes any chance of
 *     a draw row whose drawn_number says "7" but whose game_number_id
 *     points at the row that holds "3".
 *
 *  The original (game_number_id, game_id) composite FK from migration
 *  2026_06_22_080003 stays as a redundant defense — it does not protect
 *  drawn_number, so removing it now would weaken the schema during the
 *  ALTER window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_draws', function (Blueprint $table): void {
            $table->unique(
                ['id', 'game_id', 'game_number_id'],
                'game_draws_id_game_number_unique'
            );

            $table->foreign(
                ['game_number_id', 'game_id', 'drawn_number'],
                'game_draws_number_match_composite_fk'
            )
                ->references(['id', 'game_id', 'number'])
                ->on('game_numbers')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // FKs first, then the unique they may have depended on.
        Schema::table('game_draws', function (Blueprint $table): void {
            $table->dropForeign('game_draws_number_match_composite_fk');
            $table->dropUnique('game_draws_id_game_number_unique');
        });
    }
};
