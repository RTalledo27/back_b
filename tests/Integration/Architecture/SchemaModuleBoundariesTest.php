<?php

declare(strict_types=1);

namespace Tests\Integration\Architecture;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schema-level guard: tables owned by RepeatNumberBingo must never declare
 * a foreign key towards a Commerce table. The reverse direction
 * (purchase_allocations -> game_entries) is allowed and not asserted here.
 */
final class SchemaModuleBoundariesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_no_foreign_keys_from_repeat_number_bingo_to_commerce(): void
    {
        $rows = DB::select(<<<'SQL'
            SELECT
                tc.table_name AS source_table,
                kcu.column_name AS source_column,
                ccu.table_name AS target_table
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.key_column_usage AS kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
                AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = 'public'
              AND tc.table_name IN ('games', 'game_numbers', 'game_events', 'game_entries')
              AND ccu.table_name IN (
                  'orders', 'order_items', 'number_reservations',
                  'payments', 'payment_documents', 'purchase_allocations'
              )
        SQL);

        $offenders = array_map(
            fn (object $row) => "{$row->source_table}.{$row->source_column} -> {$row->target_table}",
            $rows,
        );

        $this->assertSame(
            [],
            $offenders,
            'RepeatNumberBingo tables must not have FKs to Commerce tables. Offenders: '
            .implode(', ', $offenders)
        );
    }

    public function test_purchase_allocations_correctly_references_game_entries(): void
    {
        // Sanity check of the allowed direction: this FK MUST exist.
        $rows = DB::select(<<<'SQL'
            SELECT 1 AS exists
            FROM information_schema.table_constraints AS tc
            JOIN information_schema.constraint_column_usage AS ccu
                ON ccu.constraint_name = tc.constraint_name
                AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = 'public'
              AND tc.table_name = 'purchase_allocations'
              AND ccu.table_name = 'game_entries'
        SQL);

        $this->assertNotEmpty(
            $rows,
            'purchase_allocations.game_entry_id must FK to game_entries (Commerce -> RNB is allowed).'
        );
    }
}
