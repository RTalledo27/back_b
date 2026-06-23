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
        Schema::table('games', function (Blueprint $table): void {
            $table->timestampTz('next_draw_at')->nullable()->after('completed_at');
            $table->timestampTz('last_consumed_tick_at')->nullable()->after('next_draw_at');
            $table->timestampTz('paused_at')->nullable()->after('last_consumed_tick_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE games DROP CONSTRAINT IF EXISTS games_draw_interval_check');
            DB::statement(
                'ALTER TABLE games ADD CONSTRAINT games_draw_interval_check '
                .'CHECK (draw_interval_seconds >= 10 AND draw_interval_seconds <= 3600)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE games DROP CONSTRAINT IF EXISTS games_draw_interval_check');
        }

        Schema::table('games', function (Blueprint $table): void {
            $table->dropColumn(['next_draw_at', 'last_consumed_tick_at', 'paused_at']);
        });
    }
};
