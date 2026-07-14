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
        Schema::create('payment_gateway_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('provider', 60);
            $table->string('environment', 30);
            $table->string('idempotency_key_hash', 128);
            $table->string('request_fingerprint', 128);
            $table->string('status', 40);
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('provider_attempt_id', 160)->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'idempotency_key_hash']);
            $table->index(['order_id', 'created_at']);
            $table->index(['payment_id', 'created_at']);
            $table->index(['provider', 'provider_attempt_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_gateway_attempts ADD CONSTRAINT payment_gateway_attempts_provider_check CHECK (btrim(provider) <> '')");
            DB::statement("ALTER TABLE payment_gateway_attempts ADD CONSTRAINT payment_gateway_attempts_environment_check CHECK (btrim(environment) <> '')");
            DB::statement("ALTER TABLE payment_gateway_attempts ADD CONSTRAINT payment_gateway_attempts_status_check CHECK (btrim(status) <> '')");
            DB::statement('ALTER TABLE payment_gateway_attempts ADD CONSTRAINT payment_gateway_attempts_amount_check CHECK (amount_cents > 0)');
            DB::statement("ALTER TABLE payment_gateway_attempts ADD CONSTRAINT payment_gateway_attempts_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_attempts');
    }
};
