<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 hardening. Adds UNIQUE(id, game_id, game_number_id) on
 * game_entries so game_winners can declare a composite FK
 * (game_entry_id, game_id, game_number_id) -> game_entries(...) and
 * therefore guarantee that the winning entry refers to exactly the same
 * number as the winning draw and the winning game_number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_entries', function (Blueprint $table): void {
            $table->unique(['id', 'game_id', 'game_number_id'], 'game_entries_id_game_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('game_entries', function (Blueprint $table): void {
            $table->dropUnique('game_entries_id_game_number_unique');
        });
    }
};
