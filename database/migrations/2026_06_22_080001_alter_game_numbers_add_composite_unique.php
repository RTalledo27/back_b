<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds UNIQUE(id, game_id) so other tables (game_draws,
 * game_number_counters, game_winners, game_entries) can declare a composite
 * foreign key (game_number_id, game_id) -> game_numbers(id, game_id),
 * preventing cross-game references at the database level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_numbers', function (Blueprint $table): void {
            $table->unique(['id', 'game_id'], 'game_numbers_id_game_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('game_numbers', function (Blueprint $table): void {
            $table->dropUnique('game_numbers_id_game_id_unique');
        });
    }
};
