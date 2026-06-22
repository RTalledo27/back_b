<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GameEntry lives in RepeatNumberBingo. It must NOT reference any Commerce
 * table: the cross-module link is held by purchase_allocations (Commerce)
 * which can safely reference game_entries because the allowed dependency
 * direction is Commerce -> RepeatNumberBingo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('game_id')
                ->constrained('games')
                ->restrictOnDelete();

            $table->foreignUuid('game_number_id')
                ->unique()
                ->constrained('game_numbers')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('status', 16)->default('confirmed');

            $table->timestampTz('confirmed_at');

            $table->timestampsTz();

            $table->index(['user_id', 'game_id']);
            $table->index(['game_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE game_entries ADD CONSTRAINT game_entries_status_check CHECK (status IN ('
                ."'confirmed','cancelled','refunded','winner'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_entries');
    }
};
