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
        Schema::create('payment_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();

            $table->string('disk', 24);
            $table->string('path', 255);
            $table->string('original_filename', 255);
            $table->string('mime_type', 127);
            $table->integer('size_bytes');
            $table->char('sha256', 64);

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->restrictOnDelete();

            // Append-only: created_at only, no updated_at.
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['payment_id', 'sha256']);
            $table->unique(['disk', 'path']);
            $table->index('payment_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE payment_documents ADD CONSTRAINT payment_documents_size_check '
                .'CHECK (size_bytes > 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_documents');
    }
};
