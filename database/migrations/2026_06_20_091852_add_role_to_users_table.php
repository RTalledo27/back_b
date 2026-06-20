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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 16)->default('player')->after('email');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin','player'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
