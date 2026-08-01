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
        Schema::table('winner_payout_documents', function (Blueprint $table): void {
            $table->string('document_type', 32)->default('legacy_proof')->after('payout_id');
            $table->foreignUuid('execution_attempt_id')->nullable()->after('document_type')->constrained('winner_payout_execution_attempts')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE winner_payout_documents ADD CONSTRAINT winner_payout_documents_type_check CHECK (document_type IN ('execution_proof','supporting_document','legacy_proof'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE winner_payout_documents DROP CONSTRAINT IF EXISTS winner_payout_documents_type_check');
        }

        Schema::table('winner_payout_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('execution_attempt_id');
            $table->dropColumn('document_type');
        });
    }
};
