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
        Schema::create('payment_gateway_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_gateway_attempt_id')
                ->constrained('payment_gateway_attempts')
                ->restrictOnDelete();
            $table->foreignUuid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('provider', 60);
            $table->string('provider_transaction_id', 160);
            $table->string('status', 40);
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('raw_reference_hash', 128)->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_transaction_id']);
            $table->index(['payment_id', 'created_at']);
            $table->index(['payment_gateway_attempt_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_gateway_transactions ADD CONSTRAINT payment_gateway_transactions_provider_check CHECK (btrim(provider) <> '')");
            DB::statement("ALTER TABLE payment_gateway_transactions ADD CONSTRAINT payment_gateway_transactions_transaction_id_check CHECK (btrim(provider_transaction_id) <> '')");
            DB::statement("ALTER TABLE payment_gateway_transactions ADD CONSTRAINT payment_gateway_transactions_status_check CHECK (btrim(status) <> '')");
            DB::statement('ALTER TABLE payment_gateway_transactions ADD CONSTRAINT payment_gateway_transactions_amount_check CHECK (amount_cents > 0)');
            DB::statement("ALTER TABLE payment_gateway_transactions ADD CONSTRAINT payment_gateway_transactions_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_transactions');
    }
};
