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
        Schema::create('oauth_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32);

            // SHA-256 hex of plain state — unique, single-use.
            $table->char('state_hash', 64)->unique();

            // SHA-256 hex of exchange code — set after successful callback.
            // Partial unique index (WHERE NOT NULL) allows multiple NULL rows.
            $table->char('exchange_code_hash', 64)->nullable();

            // Resolved user — set after successful identity resolution.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE oauth_attempts ADD CONSTRAINT oauth_attempts_provider_check
                 CHECK (provider IN ('google','facebook'))"
            );
            DB::statement(
                "ALTER TABLE oauth_attempts ADD CONSTRAINT oauth_attempts_state_hash_format_check
                 CHECK (state_hash ~ '^[a-f0-9]{64}$')"
            );
            DB::statement(
                "ALTER TABLE oauth_attempts ADD CONSTRAINT oauth_attempts_exchange_code_hash_format_check
                 CHECK (exchange_code_hash IS NULL OR exchange_code_hash ~ '^[a-f0-9]{64}$')"
            );
            // Partial unique index: exchange codes are globally unique when set.
            DB::statement(
                'CREATE UNIQUE INDEX oauth_attempts_exchange_code_hash_unique
                 ON oauth_attempts (exchange_code_hash) WHERE exchange_code_hash IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_attempts');
    }
};
