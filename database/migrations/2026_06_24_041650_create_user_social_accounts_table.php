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
        Schema::create('user_social_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('provider', 32);
            $table->string('provider_user_id', 191);
            $table->string('provider_email')->nullable();
            $table->timestampTz('provider_email_verified_at')->nullable();

            $table->timestampsTz();

            $table->unique(['provider', 'provider_user_id'], 'user_social_accounts_provider_identity_unique');
            $table->unique(['user_id', 'provider'], 'user_social_accounts_user_provider_unique');
            $table->index(['provider', 'provider_email'], 'user_social_accounts_provider_email_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE user_social_accounts ADD CONSTRAINT user_social_accounts_provider_check CHECK (provider IN ('google','facebook'))"
            );
            DB::statement(
                "ALTER TABLE user_social_accounts ADD CONSTRAINT user_social_accounts_provider_user_id_not_empty_check CHECK (provider_user_id <> '')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_accounts');
    }
};
