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
        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('invited_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            $table->index('expires_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX user_invitations_one_active_per_user_idx '
                .'ON user_invitations (user_id) WHERE consumed_at IS NULL AND revoked_at IS NULL'
            );
            DB::statement(
                'CREATE INDEX user_invitations_active_expires_at_idx '
                .'ON user_invitations (expires_at) WHERE consumed_at IS NULL AND revoked_at IS NULL'
            );
            DB::statement(
                "ALTER TABLE user_invitations ADD CONSTRAINT user_invitations_token_hash_hex_check CHECK (token_hash ~ '^[a-f0-9]{64}$')"
            );
            DB::statement(
                'ALTER TABLE user_invitations ADD CONSTRAINT user_invitations_terminal_state_check '
                .'CHECK (NOT (consumed_at IS NOT NULL AND revoked_at IS NOT NULL))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
