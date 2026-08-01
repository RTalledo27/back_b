<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_prize_fundings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('game_id')
                ->constrained('games')
                ->restrictOnDelete();
            $table->string('status', 32);
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->foreignId('funded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestampTz('funded_at')->nullable();
            $table->timestampTz('reserved_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->string('release_reason_code', 64)->nullable();
            $table->char('idempotency_key_hash', 64)->nullable()->unique();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestampsTz();

            $table->unique('game_id');
            $table->index(['status', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE game_prize_fundings ADD CONSTRAINT game_prize_fundings_status_check '
                ."CHECK (status IN ('legacy_unverified','unfunded','funded','reserved','released'))"
            );
            DB::statement(
                'ALTER TABLE game_prize_fundings ADD CONSTRAINT game_prize_fundings_amount_check '
                .'CHECK (amount_cents > 0)'
            );
            DB::statement(
                'ALTER TABLE game_prize_fundings ADD CONSTRAINT game_prize_fundings_currency_check '
                ."CHECK (currency ~ '^[A-Z]{3}$')"
            );
        }

        $now = now();

        foreach (DB::table('games')->select(['id', 'prize_cents', 'currency', 'created_at', 'updated_at'])->get() as $game) {
            DB::table('game_prize_fundings')->insert([
                'id' => (string) Str::uuid7(),
                'game_id' => $game->id,
                'status' => 'legacy_unverified',
                'amount_cents' => $game->prize_cents,
                'currency' => $game->currency,
                'funded_by_user_id' => null,
                'funded_at' => null,
                'reserved_at' => null,
                'released_at' => null,
                'release_reason_code' => null,
                'idempotency_key_hash' => null,
                'request_fingerprint' => null,
                'created_at' => $game->created_at ?? $now,
                'updated_at' => $game->updated_at ?? $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_prize_fundings');
    }
};
