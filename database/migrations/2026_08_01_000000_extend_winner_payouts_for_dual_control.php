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
            DB::statement('ALTER TABLE winner_payouts ALTER COLUMN external_reference DROP NOT NULL');
            DB::statement('ALTER TABLE winner_payouts ALTER COLUMN idempotency_key_hash DROP NOT NULL');
            DB::statement('ALTER TABLE winner_payouts ALTER COLUMN request_fingerprint DROP NOT NULL');
            DB::statement('ALTER TABLE winner_payouts ALTER COLUMN processed_by_user_id DROP NOT NULL');
            DB::statement('ALTER TABLE winner_payouts ALTER COLUMN processed_at DROP NOT NULL');
        } else {
            Schema::table('winner_payouts', function (Blueprint $table): void {
                $table->text('external_reference')->nullable()->change();
                $table->char('idempotency_key_hash', 64)->nullable()->change();
                $table->char('request_fingerprint', 64)->nullable()->change();
                $table->foreignId('processed_by_user_id')->nullable()->change();
                $table->timestampTz('processed_at')->nullable()->change();
            });
        }

        Schema::table('winner_payouts', function (Blueprint $table): void {
            $table->string('status', 32)->default('legacy_registered')->after('method');
            $table->foreignUuid('winner_claim_id')->nullable()->after('game_id')->constrained('winner_claims')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->after('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->after('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('executed_by_user_id')->nullable()->after('approved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('approval_rejection_reason_code', 64)->nullable();
            $table->string('failure_reason_code', 64)->nullable();
            $table->string('cancellation_reason_code', 64)->nullable();
            $table->uuid('current_destination_id')->nullable();
            $table->uuid('current_execution_attempt_id')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE winner_payouts ADD CONSTRAINT winner_payouts_status_check CHECK (status IN ('legacy_registered','draft','awaiting_approval','approved','processing','paid','failed','cancelled'))");
            DB::statement('ALTER TABLE winner_payouts ADD CONSTRAINT winner_payouts_amount_positive CHECK (amount_cents > 0)');
            DB::statement("ALTER TABLE winner_payouts ADD CONSTRAINT winner_payouts_actor_separation_check CHECK (approved_by_user_id IS NULL OR created_by_user_id IS NULL OR approved_by_user_id <> created_by_user_id)");
            DB::statement("ALTER TABLE winner_payouts ADD CONSTRAINT winner_payouts_executor_separation_check CHECK (executed_by_user_id IS NULL OR created_by_user_id IS NULL OR executed_by_user_id <> created_by_user_id)");
            DB::statement('CREATE UNIQUE INDEX winner_payouts_claim_unique ON winner_payouts (winner_claim_id) WHERE winner_claim_id IS NOT NULL');
        }

        DB::table('winner_payouts')->update([
            'status' => 'legacy_registered',
            'updated_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('winner_payouts', 'status') && DB::table('winner_payouts')->where('status', '<>', 'legacy_registered')->exists()) {
            throw new \LogicException('Cannot roll back winner payout lifecycle while non-legacy payout rows exist.');
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS winner_payouts_claim_unique');
            DB::statement('ALTER TABLE winner_payouts DROP CONSTRAINT IF EXISTS winner_payouts_status_check');
            DB::statement('ALTER TABLE winner_payouts DROP CONSTRAINT IF EXISTS winner_payouts_amount_positive');
            DB::statement('ALTER TABLE winner_payouts DROP CONSTRAINT IF EXISTS winner_payouts_actor_separation_check');
            DB::statement('ALTER TABLE winner_payouts DROP CONSTRAINT IF EXISTS winner_payouts_executor_separation_check');
        }

        Schema::table('winner_payouts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('winner_claim_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('submitted_by_user_id');
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropConstrainedForeignId('executed_by_user_id');
            $table->dropColumn([
                'status', 'submitted_at', 'approved_at', 'processing_at', 'paid_at',
                'failed_at', 'cancelled_at', 'approval_rejection_reason_code',
                'failure_reason_code', 'cancellation_reason_code', 'current_destination_id',
                'current_execution_attempt_id', 'updated_at',
            ]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE winner_payouts ALTER COLUMN external_reference SET NOT NULL');
            DB::statement('ALTER TABLE winner_payouts ALTER COLUMN idempotency_key_hash SET NOT NULL');
            DB::statement('ALTER TABLE winner_payouts ALTER COLUMN request_fingerprint SET NOT NULL');
            DB::statement('ALTER TABLE winner_payouts ALTER COLUMN processed_by_user_id SET NOT NULL');
            DB::statement('ALTER TABLE winner_payouts ALTER COLUMN processed_at SET NOT NULL');
        } else {
            Schema::table('winner_payouts', function (Blueprint $table): void {
                $table->text('external_reference')->nullable(false)->change();
                $table->char('idempotency_key_hash', 64)->nullable(false)->change();
                $table->char('request_fingerprint', 64)->nullable(false)->change();
                $table->foreignId('processed_by_user_id')->nullable(false)->change();
                $table->timestampTz('processed_at')->nullable(false)->change();
            });
        }
    }
};
