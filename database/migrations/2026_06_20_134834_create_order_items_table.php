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
        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            $table->foreignUuid('game_number_id')
                ->constrained('game_numbers')
                ->restrictOnDelete();

            $table->bigInteger('unit_price_cents');

            $table->timestampsTz();

            $table->unique(['order_id', 'game_number_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_check '
                .'CHECK (unit_price_cents >= 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
