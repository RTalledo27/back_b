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
        Schema::create('games', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('slug', 120)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();

            $table->integer('number_min');
            $table->integer('number_max');
            $table->integer('hits_required');

            $table->bigInteger('ticket_price_cents');
            $table->bigInteger('prize_cents');
            $table->char('currency', 3);

            $table->timestampTz('sales_opens_at')->nullable();
            $table->timestampTz('sales_closes_at')->nullable();
            $table->timestampTz('scheduled_start_at')->nullable();

            $table->integer('draw_interval_seconds')->default(30);
            $table->boolean('auto_draw_enabled')->default(true);

            $table->string('status', 24)->default('draft');

            $table->jsonb('settings')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestampsTz();

            $table->index(['status', 'sales_opens_at']);
            $table->index(['status', 'scheduled_start_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE games ADD CONSTRAINT games_status_check CHECK (status IN ("
                ."'draft','published','sales_open','sales_closed',"
                ."'running','paused','resolving','completed','cancelled'))"
            );
            DB::statement('ALTER TABLE games ADD CONSTRAINT games_number_min_check CHECK (number_min >= 1)');
            DB::statement('ALTER TABLE games ADD CONSTRAINT games_number_max_check CHECK (number_max > number_min)');
            DB::statement('ALTER TABLE games ADD CONSTRAINT games_hits_required_check CHECK (hits_required >= 2)');
            DB::statement('ALTER TABLE games ADD CONSTRAINT games_ticket_price_check CHECK (ticket_price_cents >= 0)');
            DB::statement('ALTER TABLE games ADD CONSTRAINT games_prize_check CHECK (prize_cents >= 0)');
            DB::statement('ALTER TABLE games ADD CONSTRAINT games_draw_interval_check CHECK (draw_interval_seconds >= 1)');
            DB::statement("ALTER TABLE games ADD CONSTRAINT games_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
