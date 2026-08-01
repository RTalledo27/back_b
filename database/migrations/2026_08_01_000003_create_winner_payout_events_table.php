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
        Schema::create('winner_payout_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('winner_payout_id')->constrained('winner_payouts')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 32);
            $table->string('reason_code', 64)->nullable();
            $table->jsonb('safe_metadata')->default('{}');
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['winner_payout_id', 'occurred_at']);
            $table->index('event_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE winner_payout_events ADD CONSTRAINT winner_payout_events_type_check CHECK (event_type IN ('payout_created','payout_destination_added','payout_submitted_for_approval','payout_approval_rejected','payout_approved','payout_processing_started','payout_execution_recorded','payout_execution_failed','payout_cancelled','legacy_payout_initialized'))");
        }

        foreach (DB::table('winner_payouts')->select(['id', 'created_at'])->orderBy('id')->get() as $payout) {
            DB::table('winner_payout_events')->insert([
                'id' => (string) Str::uuid7(),
                'winner_payout_id' => $payout->id,
                'event_type' => 'legacy_payout_initialized',
                'from_status' => null,
                'to_status' => 'legacy_registered',
                'actor_user_id' => null,
                'actor_type' => 'system',
                'reason_code' => 'historical_migration',
                'safe_metadata' => json_encode(['source' => 'phase_6_3'], JSON_THROW_ON_ERROR),
                'correlation_id' => null,
                'occurred_at' => $payout->created_at ?? now(),
                'created_at' => $payout->created_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_payout_events');
    }
};
