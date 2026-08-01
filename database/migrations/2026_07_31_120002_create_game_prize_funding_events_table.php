<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_prize_funding_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_prize_funding_id')
                ->constrained('game_prize_fundings')
                ->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('reason_code', 64)->nullable();
            $table->jsonb('safe_metadata')->default('{}');
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['game_prize_funding_id', 'occurred_at']);
            $table->index('event_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE game_prize_funding_events ADD CONSTRAINT gpf_events_type_check '
                ."CHECK (event_type IN ('funding_created','funding_recorded','funding_reserved','funding_released'))"
            );
        }

        foreach (DB::table('game_prize_fundings')->select(['id', 'status', 'created_at'])->get() as $funding) {
            DB::table('game_prize_funding_events')->insert([
                'id' => (string) Str::uuid7(),
                'game_prize_funding_id' => $funding->id,
                'event_type' => 'funding_created',
                'from_status' => null,
                'to_status' => $funding->status,
                'actor_user_id' => null,
                'reason_code' => 'historical_migration',
                'safe_metadata' => json_encode(['source' => 'phase_11_2a_migration'], JSON_THROW_ON_ERROR),
                'correlation_id' => null,
                'occurred_at' => $funding->created_at ?? now(),
                'created_at' => $funding->created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_prize_funding_events');
    }
};
