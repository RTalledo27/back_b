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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
            DB::statement(
                'ALTER TABLE users ADD CONSTRAINT users_password_nullable_or_present_check '
                .'CHECK (password IS NULL OR length(password) > 0)'
            );

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_password_nullable_or_present_check');
            DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL');

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};
