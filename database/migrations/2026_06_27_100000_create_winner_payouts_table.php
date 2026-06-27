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
        Schema::create('winner_payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // UUID v7 assigned by Laravel HasUuids — no PostgreSQL DEFAULT.
            $table->foreignUuid('game_winner_id')->unique()->constrained('game_winners')->restrictOnDelete();
            $table->foreignUuid('game_id')->constrained('games')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('method', 24)->default('manual');
            $table->text('external_reference');
            $table->text('notes')->nullable();
            $table->char('idempotency_key_hash', 64);
            $table->char('request_fingerprint', 64);
            $table->foreignId('processed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('processed_at');
            $table->timestampTz('created_at');
            // No updated_at: append-only.
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE winner_payouts ADD CONSTRAINT wp_amount_check CHECK (amount_cents > 0)');
            DB::statement("ALTER TABLE winner_payouts ADD CONSTRAINT wp_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE winner_payouts ADD CONSTRAINT wp_method_check CHECK (method = 'manual')");
            DB::statement("ALTER TABLE winner_payouts ADD CONSTRAINT wp_ref_check CHECK (btrim(external_reference) <> '')");
            DB::statement("ALTER TABLE winner_payouts ADD CONSTRAINT wp_key_hash_check CHECK (idempotency_key_hash ~ '^[a-f0-9]{64}$')");
            DB::statement("ALTER TABLE winner_payouts ADD CONSTRAINT wp_fingerprint_check CHECK (request_fingerprint ~ '^[a-f0-9]{64}$')");
            DB::statement('ALTER TABLE winner_payouts ADD CONSTRAINT wp_idempotency_unique UNIQUE (idempotency_key_hash)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_payouts');
    }
};
