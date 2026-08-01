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
        Schema::create('winner_payout_execution_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('winner_payout_id')->constrained('winner_payouts')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 16);
            $table->foreignUuid('destination_id')->constrained('winner_payout_destinations')->restrictOnDelete();
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('external_reference_encrypted')->nullable();
            $table->string('external_reference_masked', 128)->nullable();
            $table->string('failure_reason_code', 64)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->char('idempotency_key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['winner_payout_id', 'attempt_number']);
            $table->unique(['id', 'winner_payout_id'], 'winner_payout_attempts_id_payout_unique');
            $table->unique('idempotency_key_hash');
            $table->index(['winner_payout_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE winner_payout_execution_attempts ADD CONSTRAINT winner_payout_execution_attempts_status_check CHECK (status IN ('processing','paid','failed'))");
            DB::statement("ALTER TABLE winner_payout_execution_attempts ADD CONSTRAINT winner_payout_execution_attempts_hash_check CHECK (idempotency_key_hash ~ '^[a-f0-9]{64}$' AND request_fingerprint ~ '^[a-f0-9]{64}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_payout_execution_attempts');
    }
};
