<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency table for the engine's draw command. There is no `pending`
 * state and no recovery path — a row exists only when its draw was fully
 * committed inside the same transaction. If the transaction fails, both
 * the draw and the command row roll back together.
 *
 * Append-only: the row is INSERTed exactly once at the end of the draw
 * transaction with all fields already resolved. The model blocks update
 * and delete.
 *
 * Composite FK on (game_draw_id, game_id) prevents a command referencing
 * a draw from another game.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draw_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('game_id')
                ->constrained('games')
                ->restrictOnDelete();

            $table->uuid('command_id');
            $table->uuid('game_draw_id');

            $table->jsonb('result_payload');
            $table->timestampTz('completed_at');

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['game_id', 'command_id'], 'draw_commands_game_command_unique');
            $table->unique('game_draw_id', 'draw_commands_draw_unique');

            $table->foreign(['game_draw_id', 'game_id'], 'draw_commands_draw_game_composite_fk')
                ->references(['id', 'game_id'])
                ->on('game_draws')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_commands');
    }
};
