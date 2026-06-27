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
        Schema::create('refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // UUID v7 assigned by Laravel HasUuids — no PostgreSQL DEFAULT.

            $table->foreignUuid('order_id')
                ->unique()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->foreignUuid('payment_id')
                ->unique()
                ->constrained('payments')
                ->restrictOnDelete();

            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->text('reason');

            $table->char('idempotency_key_hash', 64);
            $table->char('request_fingerprint', 64);

            $table->foreignId('processed_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('processed_at');
            $table->timestampTz('created_at');
            // No updated_at: append-only record.
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount_cents > 0)');
            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_reason_check CHECK (btrim(reason) <> '')");
            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_key_hash_check CHECK (idempotency_key_hash ~ '^[a-f0-9]{64}$')");
            DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_fingerprint_check CHECK (request_fingerprint ~ '^[a-f0-9]{64}$')");
            DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_idempotency_key_unique UNIQUE (idempotency_key_hash)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
