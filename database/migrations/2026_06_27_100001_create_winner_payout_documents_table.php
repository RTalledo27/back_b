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
        Schema::create('winner_payout_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // UUID v7 assigned by Laravel HasUuids — no PostgreSQL DEFAULT.
            $table->foreignUuid('payout_id')->constrained('winner_payouts')->restrictOnDelete();
            $table->string('disk', 64);
            $table->text('path');
            $table->text('original_filename');
            $table->string('mime_type', 128);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            // No updated_at: append-only.
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE winner_payout_documents ADD CONSTRAINT wpd_size_check CHECK (size_bytes > 0)');
            DB::statement("ALTER TABLE winner_payout_documents ADD CONSTRAINT wpd_sha256_check CHECK (sha256 ~ '^[a-f0-9]{64}$')");
            DB::statement("ALTER TABLE winner_payout_documents ADD CONSTRAINT wpd_disk_check CHECK (btrim(disk) <> '')");
            DB::statement("ALTER TABLE winner_payout_documents ADD CONSTRAINT wpd_path_check CHECK (btrim(path) <> '')");
            DB::statement("ALTER TABLE winner_payout_documents ADD CONSTRAINT wpd_filename_check CHECK (btrim(original_filename) <> '')");
            DB::statement("ALTER TABLE winner_payout_documents ADD CONSTRAINT wpd_mime_check CHECK (btrim(mime_type) <> '')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_payout_documents');
    }
};
