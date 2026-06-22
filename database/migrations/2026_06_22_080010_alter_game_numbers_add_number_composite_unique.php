<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 hardening. Adds UNIQUE(id, game_id, number) on game_numbers so
 * game_draws can declare a composite FK
 * (game_number_id, game_id, drawn_number) -> game_numbers(id, game_id, number)
 * that forces drawn_number to match the actual number column of the referenced
 * row — not only that the row belongs to the same game.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_numbers', function (Blueprint $table): void {
            $table->unique(['id', 'game_id', 'number'], 'game_numbers_id_game_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('game_numbers', function (Blueprint $table): void {
            $table->dropUnique('game_numbers_id_game_number_unique');
        });
    }
};
