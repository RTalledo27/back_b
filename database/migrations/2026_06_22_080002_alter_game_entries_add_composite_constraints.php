<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three Phase 3 additions on top of game_entries (created in Phase 2.1):
 *
 *  1. UNIQUE(id, game_id) — required so game_winners can FK on
 *     (game_entry_id, game_id) to enforce same-aggregate references.
 *  2. Composite FK (game_number_id, game_id) -> game_numbers(id, game_id).
 *     The simple FK on game_number_id stays (different constraint, defense
 *     in depth). The new one is the structural protection.
 *  3. Partial UNIQUE INDEX game_entries_one_winner_per_game on (game_id)
 *     WHERE status = 'winner'. Backs the "one Winner per game" rule even
 *     against future code paths that bypass the Action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_entries', function (Blueprint $table): void {
            $table->unique(['id', 'game_id'], 'game_entries_id_game_id_unique');

            $table->foreign(['game_number_id', 'game_id'], 'game_entries_number_game_composite_fk')
                ->references(['id', 'game_id'])
                ->on('game_numbers')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX game_entries_one_winner_per_game '
                ."ON game_entries (game_id) WHERE status = 'winner'"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS game_entries_one_winner_per_game');
        }

        Schema::table('game_entries', function (Blueprint $table): void {
            $table->dropForeign('game_entries_number_game_composite_fk');
            $table->dropUnique('game_entries_id_game_id_unique');
        });
    }
};
