<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_gateway_transactions', function (Blueprint $table): void {
            $table->timestampTz('applied_at')->nullable();
            $table->index('applied_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_gateway_transactions', function (Blueprint $table): void {
            $table->dropIndex(['applied_at']);
            $table->dropColumn('applied_at');
        });
    }
};
