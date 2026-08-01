<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('winner_payouts', function (Blueprint $table): void {
            $table->foreign(['current_destination_id', 'id'], 'winner_payouts_current_destination_payout_foreign')
                ->references(['id', 'winner_payout_id'])
                ->on('winner_payout_destinations')
                ->restrictOnDelete();
            $table->foreign(['current_execution_attempt_id', 'id'], 'winner_payouts_current_attempt_payout_foreign')
                ->references(['id', 'winner_payout_id'])
                ->on('winner_payout_execution_attempts')
                ->restrictOnDelete();
        });

        Schema::table('winner_payout_documents', function (Blueprint $table): void {
            $table->foreign(['execution_attempt_id', 'payout_id'], 'winner_payout_documents_attempt_payout_foreign')
                ->references(['id', 'winner_payout_id'])
                ->on('winner_payout_execution_attempts')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('winner_payouts', function (Blueprint $table): void {
            $table->dropForeign('winner_payouts_current_destination_payout_foreign');
            $table->dropForeign('winner_payouts_current_attempt_payout_foreign');
        });

        Schema::table('winner_payout_documents', function (Blueprint $table): void {
            $table->dropForeign('winner_payout_documents_attempt_payout_foreign');
        });
    }
};
