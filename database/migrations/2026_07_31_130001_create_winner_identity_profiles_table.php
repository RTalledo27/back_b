<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winner_identity_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('winner_claim_id')
                ->unique()
                ->constrained('winner_claims')
                ->restrictOnDelete();
            $table->string('document_type', 32);
            $table->text('legal_name_encrypted');
            $table->text('document_number_encrypted');
            $table->string('document_number_masked', 64);
            $table->timestampTz('accepted_prize_terms_at');
            $table->timestampTz('consented_identity_processing_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_identity_profiles');
    }
};
