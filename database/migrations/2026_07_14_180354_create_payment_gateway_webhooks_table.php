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
        Schema::create('payment_gateway_webhooks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 60);
            $table->string('provider_event_id', 160);
            $table->string('event_type', 120);
            $table->boolean('signature_verified')->default(false);
            $table->string('payload_hash', 128);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_event_id']);
            $table->index('processed_at');
            $table->index('failed_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payment_gateway_webhooks ADD CONSTRAINT payment_gateway_webhooks_provider_check CHECK (btrim(provider) <> '')");
            DB::statement("ALTER TABLE payment_gateway_webhooks ADD CONSTRAINT payment_gateway_webhooks_event_id_check CHECK (btrim(provider_event_id) <> '')");
            DB::statement("ALTER TABLE payment_gateway_webhooks ADD CONSTRAINT payment_gateway_webhooks_event_type_check CHECK (btrim(event_type) <> '')");
            DB::statement("ALTER TABLE payment_gateway_webhooks ADD CONSTRAINT payment_gateway_webhooks_payload_hash_check CHECK (btrim(payload_hash) <> '')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_webhooks');
    }
};
