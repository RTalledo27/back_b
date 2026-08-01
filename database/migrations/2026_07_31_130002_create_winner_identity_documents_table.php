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
        Schema::create('winner_identity_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('winner_claim_id')
                ->constrained('winner_claims')
                ->restrictOnDelete();
            $table->string('document_type', 32);
            $table->string('disk', 64);
            $table->string('path', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['winner_claim_id', 'document_type']);
            $table->unique(['winner_claim_id', 'sha256']);
            $table->index('winner_claim_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE winner_identity_documents ADD CONSTRAINT winner_identity_documents_size_check CHECK (size_bytes > 0)'
            );
            DB::statement(
                'ALTER TABLE winner_identity_documents ADD CONSTRAINT winner_identity_documents_sha256_check CHECK (sha256 ~ \'^[0-9a-f]{64}$\')'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_identity_documents');
    }
};
