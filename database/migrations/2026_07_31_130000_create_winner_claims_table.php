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
        Schema::create('winner_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_winner_id')
                ->unique()
                ->constrained('game_winners')
                ->restrictOnDelete();
            $table->foreignId('winner_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('claim_reference', 64)->unique();
            $table->string('status', 32);
            $table->timestampTz('claim_window_started_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('identity_submitted_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('rejection_reason_code', 64)->nullable();
            $table->char('idempotency_key_hash', 64)->nullable()->unique();
            $table->char('request_fingerprint', 64)->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->timestampsTz();

            $table->index(['status', 'expires_at']);
            $table->index(['winner_user_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE winner_claims ADD CONSTRAINT winner_claims_status_check CHECK (status IN ('pending_claim','identity_pending','verified','rejected','expired'))"
            );
        }

        foreach (DB::table('game_winners')->select(['id', 'user_id'])->orderBy('id')->get() as $winner) {
            $createdAt = now();

            DB::table('winner_claims')->insert([
                'id' => (string) Str::uuid7(),
                'game_winner_id' => $winner->id,
                'winner_user_id' => $winner->user_id,
                'claim_reference' => 'LEGACY-'.strtoupper(Str::random(32)),
                'status' => 'pending_claim',
                'is_legacy' => true,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_claims');
    }
};
