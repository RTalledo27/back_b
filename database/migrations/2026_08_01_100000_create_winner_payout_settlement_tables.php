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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE winner_payouts DROP CONSTRAINT IF EXISTS winner_payouts_status_check');
            DB::statement("ALTER TABLE winner_payouts ADD CONSTRAINT winner_payouts_status_check CHECK (status IN ('legacy_registered','draft','awaiting_approval','approved','processing','paid','disputed','failed','cancelled'))");
            DB::statement('ALTER TABLE winner_payout_events DROP CONSTRAINT IF EXISTS winner_payout_events_type_check');
        }

        Schema::create('winner_payout_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('winner_payout_id')->unique()->constrained('winner_payouts')->restrictOnDelete();
            $table->foreignId('winner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24);
            $table->timestampTz('confirmation_window_started_at')->nullable();
            $table->timestampTz('confirmation_expires_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->string('confirmation_method', 64)->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['status', 'confirmation_expires_at']);
        });

        Schema::create('winner_payout_disputes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('winner_payout_id')->constrained('winner_payouts')->restrictOnDelete();
            $table->foreignId('winner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24);
            $table->string('reason_code', 48);
            $table->text('description_encrypted')->nullable();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('resolution_code', 48)->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('review_started_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['winner_payout_id', 'status']);
        });

        Schema::create('winner_payout_reconciliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('winner_payout_id')->constrained('winner_payouts')->restrictOnDelete();
            $table->foreignUuid('execution_attempt_id')->constrained('winner_payout_execution_attempts')->restrictOnDelete();
            $table->string('status', 24);
            $table->string('result_code', 48);
            $table->foreignId('reconciled_by_user_id')->constrained('users')->restrictOnDelete();
            $table->char('reference_digest', 64)->nullable();
            $table->text('notes_encrypted')->nullable();
            $table->char('idempotency_key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->timestampTz('reconciled_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique('idempotency_key_hash');
            $table->index(['winner_payout_id', 'created_at']);
        });

        Schema::create('game_financial_closures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')->unique()->constrained('games')->restrictOnDelete();
            $table->foreignUuid('game_winner_id')->constrained('game_winners')->restrictOnDelete();
            $table->foreignUuid('winner_payout_id')->constrained('winner_payouts')->restrictOnDelete();
            $table->foreignUuid('winner_payout_receipt_id')->constrained('winner_payout_receipts')->restrictOnDelete();
            $table->foreignUuid('winner_payout_reconciliation_id')->constrained('winner_payout_reconciliations')->restrictOnDelete();
            $table->foreignId('closed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('closure_basis', 48);
            $table->jsonb('safe_snapshot');
            $table->uuid('correlation_id');
            $table->timestampTz('closed_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE winner_payout_receipts ADD CONSTRAINT winner_payout_receipts_status_check CHECK (status IN ('pending','confirmed','window_expired'))");
            DB::statement("ALTER TABLE winner_payout_disputes ADD CONSTRAINT winner_payout_disputes_status_check CHECK (status IN ('open','under_review','resolved','cancelled'))");
            DB::statement("ALTER TABLE winner_payout_disputes ADD CONSTRAINT winner_payout_disputes_reason_check CHECK (reason_code IN ('funds_not_received','incorrect_amount','incorrect_destination','duplicate_payment','unrecognized_payment','other'))");
            DB::statement("ALTER TABLE winner_payout_disputes ADD CONSTRAINT winner_payout_disputes_resolution_check CHECK (resolution_code IS NULL OR resolution_code IN ('payment_confirmed','retry_required','corrective_action_required','claim_rejected','withdrawn_by_winner'))");
            DB::statement("ALTER TABLE winner_payout_reconciliations ADD CONSTRAINT winner_payout_reconciliations_status_check CHECK (status IN ('pending','matched','discrepancy','corrected'))");
            DB::statement("ALTER TABLE winner_payout_reconciliations ADD CONSTRAINT winner_payout_reconciliations_result_check CHECK (result_code IN ('amount_and_reference_match','amount_mismatch','currency_mismatch','reference_mismatch','destination_mismatch','proof_missing','duplicate_reference','manual_verification_required'))");
            DB::statement("ALTER TABLE game_financial_closures ADD CONSTRAINT game_financial_closures_basis_check CHECK (closure_basis IN ('winner_confirmed','confirmation_window_expired'))");
            DB::statement('CREATE UNIQUE INDEX winner_payout_disputes_active_unique ON winner_payout_disputes (winner_payout_id) WHERE status IN (\'open\', \'under_review\')');
            DB::statement("ALTER TABLE winner_payout_events ADD CONSTRAINT winner_payout_events_type_check CHECK (event_type IN ('payout_created','payout_destination_added','payout_submitted_for_approval','payout_approval_rejected','payout_approved','payout_processing_started','payout_execution_recorded','payout_execution_failed','payout_cancelled','legacy_payout_initialized','winner_receipt_created','winner_receipt_confirmed','winner_receipt_window_expired','winner_dispute_opened','winner_dispute_review_started','winner_dispute_resolved','payout_reconciliation_recorded','payout_reconciliation_discrepancy','game_financially_closed'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS winner_payout_disputes_active_unique');
            DB::statement('ALTER TABLE winner_payout_events DROP CONSTRAINT IF EXISTS winner_payout_events_type_check');
            DB::statement("ALTER TABLE winner_payout_events ADD CONSTRAINT winner_payout_events_type_check CHECK (event_type IN ('payout_created','payout_destination_added','payout_submitted_for_approval','payout_approval_rejected','payout_approved','payout_processing_started','payout_execution_recorded','payout_execution_failed','payout_cancelled','legacy_payout_initialized'))");
        }

        Schema::dropIfExists('game_financial_closures');
        Schema::dropIfExists('winner_payout_reconciliations');
        Schema::dropIfExists('winner_payout_disputes');
        Schema::dropIfExists('winner_payout_receipts');
    }
};
