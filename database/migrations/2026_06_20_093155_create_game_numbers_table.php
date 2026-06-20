<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_numbers', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('game_id')
                ->constrained('games')
                ->cascadeOnDelete();

            $table->integer('number');
            $table->string('status', 16)->default('available');

            $table->timestampsTz();

            $table->unique(['game_id', 'number']);
            $table->index(['game_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE game_numbers ADD CONSTRAINT game_numbers_status_check "
                ."CHECK (status IN ('available','reserved','sold'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_numbers');
    }
};
