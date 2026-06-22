<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-module link table living in Commerce. Holds the precise mapping
 * order_item <-> game_entry produced by an approved payment.
 *
 * The Commerce -> RepeatNumberBingo direction is allowed; that is why
 * purchase_allocations.game_entry_id may FK to a RNB table. The reverse
 * direction (RNB referencing Commerce) is forbidden by ModuleBoundariesTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('order_item_id')
                ->unique()
                ->constrained('order_items')
                ->restrictOnDelete();

            $table->foreignUuid('game_entry_id')
                ->unique()
                ->constrained('game_entries')
                ->restrictOnDelete();

            $table->foreignUuid('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();

            // Append-only: created_at only.
            $table->timestampTz('created_at')->useCurrent();

            $table->index('payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_allocations');
    }
};
