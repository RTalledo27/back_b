<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            $table->foreignUuid('game_number_id')
                ->constrained('game_numbers')
                ->restrictOnDelete();

            $table->timestampsTz();

            // Active-hold uniqueness: at most one reservation row per game_number.
            // Owner and expiration live on the parent order — single source of truth.
            $table->unique('game_number_id');
            $table->unique(['order_id', 'game_number_id']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_reservations');
    }
};
