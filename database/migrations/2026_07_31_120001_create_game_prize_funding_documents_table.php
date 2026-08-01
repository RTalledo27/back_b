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
        Schema::create('game_prize_funding_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_prize_funding_id')
                ->constrained('game_prize_fundings')
                ->restrictOnDelete();
            $table->string('disk', 64);
            $table->text('path');
            $table->text('original_filename');
            $table->string('mime_type', 128);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['disk', 'path']);
            $table->unique(['game_prize_funding_id', 'sha256']);
            $table->index('game_prize_funding_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE game_prize_funding_documents ADD CONSTRAINT gpf_documents_size_check '
                .'CHECK (size_bytes > 0)'
            );
            DB::statement(
                'ALTER TABLE game_prize_funding_documents ADD CONSTRAINT gpf_documents_sha256_check '
                ."CHECK (sha256 ~ '^[a-f0-9]{64}$')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_prize_funding_documents');
    }
};
