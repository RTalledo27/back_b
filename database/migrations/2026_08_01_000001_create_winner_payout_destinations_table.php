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
        Schema::create('winner_payout_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('winner_payout_id')->constrained('winner_payouts')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('method', 32);
            $table->text('destination_payload_encrypted');
            $table->string('destination_masked', 128);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['winner_payout_id', 'version']);
            $table->unique(['id', 'winner_payout_id'], 'winner_payout_destinations_id_payout_unique');
            $table->index(['winner_payout_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE winner_payout_destinations ADD CONSTRAINT winner_payout_destinations_method_check CHECK (method IN ('bank_transfer','yape','plin','cash','other'))");
            DB::statement("ALTER TABLE winner_payout_destinations ADD CONSTRAINT winner_payout_destinations_mask_check CHECK (btrim(destination_masked) <> '')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_payout_destinations');
    }
};
