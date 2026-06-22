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
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('order_id')
                ->unique()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('method', 24)->default('manual');
            $table->string('status', 24)->default('pending');

            $table->timestampTz('submitted_at')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestampsTz();

            $table->index(['status', 'submitted_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('
                ."'pending','under_review','approved','rejected','cancelled','refunded'))"
            );
            DB::statement(
                "ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('manual'))"
            );
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount_cents >= 0)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
