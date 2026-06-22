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
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignUuid('game_id')
                ->constrained('games')
                ->restrictOnDelete();

            $table->string('status', 24)->default('pending');

            $table->bigInteger('subtotal_cents');
            $table->bigInteger('total_cents');
            $table->char('currency', 3);

            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('expired_at')->nullable();

            $table->jsonb('metadata')->nullable();

            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index(['game_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('
                ."'pending','payment_submitted','paid','rejected','expired','cancelled','refunded'))"
            );
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_subtotal_check CHECK (subtotal_cents >= 0)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_check CHECK (total_cents >= 0)');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_currency_check CHECK (currency ~ '^[A-Z]{3}$')");

            // Partial index supports the expiration job's fast scan over only
            // pending orders past their expires_at.
            DB::statement(
                'CREATE INDEX orders_pending_expires_at_idx ON orders (expires_at) '
                ."WHERE status = 'pending'"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
