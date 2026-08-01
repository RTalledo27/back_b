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
        Schema::create('winner_claim_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('winner_claim_id')
                ->constrained('winner_claims')
                ->restrictOnDelete();
            $table->string('event_type', 48);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('actor_type', 32);
            $table->string('reason_code', 64)->nullable();
            $table->jsonb('safe_metadata')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['winner_claim_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE winner_claim_events ADD CONSTRAINT winner_claim_events_type_check CHECK (event_type IN ('claim_created','claim_submitted','identity_verified','identity_rejected','claim_expired','legacy_claim_initialized'))"
            );
        }

        foreach (DB::table('winner_claims')->where('is_legacy', true)->select(['id', 'created_at'])->orderBy('id')->get() as $claim) {
            DB::table('winner_claim_events')->insert([
                'id' => (string) Str::uuid7(),
                'winner_claim_id' => $claim->id,
                'event_type' => 'legacy_claim_initialized',
                'from_status' => null,
                'to_status' => 'pending_claim',
                'actor_user_id' => null,
                'actor_type' => 'system',
                'reason_code' => 'historical_migration',
                'safe_metadata' => json_encode(['is_legacy' => true], JSON_THROW_ON_ERROR),
                'correlation_id' => null,
                'occurred_at' => $claim->created_at,
                'created_at' => $claim->created_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_claim_events');
    }
};
