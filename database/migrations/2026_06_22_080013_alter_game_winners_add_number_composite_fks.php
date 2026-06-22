<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 hardening on game_winners. Adds two composite FKs of three
 * columns that, combined with the existing UNIQUE(game_id) constraint,
 * guarantee:
 *
 *    winner.game        = entry.game        = draw.game
 *    winner.game_number = entry.game_number = draw.game_number
 *
 * Without these, the previous two-column composite FKs only enforced the
 * same-game rule. PostgreSQL is now the last line of defense against a
 * winner that picks an entry from one number and a draw from another.
 *
 * The original two-column composite FKs are kept as redundant defense.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_winners', function (Blueprint $table): void {
            $table->foreign(
                ['game_entry_id', 'game_id', 'game_number_id'],
                'game_winners_entry_number_match_composite_fk'
            )
                ->references(['id', 'game_id', 'game_number_id'])
                ->on('game_entries')
                ->restrictOnDelete();

            $table->foreign(
                ['game_draw_id', 'game_id', 'game_number_id'],
                'game_winners_draw_number_match_composite_fk'
            )
                ->references(['id', 'game_id', 'game_number_id'])
                ->on('game_draws')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_winners', function (Blueprint $table): void {
            $table->dropForeign('game_winners_draw_number_match_composite_fk');
            $table->dropForeign('game_winners_entry_number_match_composite_fk');
        });
    }
};
