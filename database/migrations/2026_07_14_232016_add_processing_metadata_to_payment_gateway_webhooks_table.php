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
        Schema::table('payment_gateway_webhooks', function (Blueprint $table): void {
            $table->string('provider_attempt_id', 160)->nullable();
            $table->string('provider_transaction_id', 160)->nullable();
            $table->string('normalized_status', 40)->nullable();
            $table->unsignedInteger('amount_cents')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('environment', 40)->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->unsignedInteger('processing_attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->index(['provider', 'provider_attempt_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_gateway_webhooks ADD CONSTRAINT payment_gateway_webhooks_processing_attempts_check CHECK (processing_attempts >= 0)');
            DB::statement('ALTER TABLE payment_gateway_webhooks ADD CONSTRAINT payment_gateway_webhooks_amount_check CHECK (amount_cents IS NULL OR amount_cents > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_gateway_webhooks DROP CONSTRAINT IF EXISTS payment_gateway_webhooks_processing_attempts_check');
            DB::statement('ALTER TABLE payment_gateway_webhooks DROP CONSTRAINT IF EXISTS payment_gateway_webhooks_amount_check');
        }

        Schema::table('payment_gateway_webhooks', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'provider_attempt_id']);
            $table->dropColumn([
                'provider_attempt_id',
                'provider_transaction_id',
                'normalized_status',
                'amount_cents',
                'currency',
                'environment',
                'occurred_at',
                'processing_attempts',
                'last_error',
            ]);
        });
    }
};
