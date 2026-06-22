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
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('request_method', 8);
            $table->string('request_path', 255);
            $table->string('key', 80);

            $table->char('payload_sha256', 64);

            $table->jsonb('result_payload')->nullable();

            $table->timestampTz('locked_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at');

            // Composite uniqueness: same key may be reused by different users
            // or for different operations without colliding.
            $table->unique(['user_id', 'request_method', 'request_path', 'key']);
            $table->index('expires_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Reclaim-abandoned scan: indexes only the still-in-progress rows.
            DB::statement(
                'CREATE INDEX idempotency_keys_in_progress_idx ON idempotency_keys (locked_at) '
                .'WHERE completed_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
