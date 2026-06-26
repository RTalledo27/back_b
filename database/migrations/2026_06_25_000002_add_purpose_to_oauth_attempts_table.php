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
        Schema::table('oauth_attempts', function (Blueprint $table): void {
            // Default 'login' so existing login-purpose rows remain valid after migration.
            $table->string('purpose', 8)->default('login')->after('provider');

            // User who initiated a link flow; NULL for login attempts.
            $table->foreignId('initiated_by_user_id')
                ->nullable()
                ->after('purpose')
                ->constrained('users')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE oauth_attempts ADD CONSTRAINT oauth_attempts_purpose_check
                 CHECK (purpose IN ('login','link'))"
            );
            // purpose='login' must have no initiator; purpose='link' must have one.
            DB::statement(
                "ALTER TABLE oauth_attempts ADD CONSTRAINT oauth_attempts_purpose_initiator_check
                 CHECK (
                     (purpose = 'login' AND initiated_by_user_id IS NULL) OR
                     (purpose = 'link'  AND initiated_by_user_id IS NOT NULL)
                 )"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE oauth_attempts DROP CONSTRAINT IF EXISTS oauth_attempts_purpose_initiator_check');
            DB::statement('ALTER TABLE oauth_attempts DROP CONSTRAINT IF EXISTS oauth_attempts_purpose_check');
        }

        Schema::table('oauth_attempts', function (Blueprint $table): void {
            $table->dropForeign(['initiated_by_user_id']);
            $table->dropColumn(['purpose', 'initiated_by_user_id']);
        });
    }
};
