<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cheap structural assertions over the five Phase 3.1 schema additions.
 * Heavier behavioural checks (UUID v7, append-only hooks, cross-game FKs)
 * live in their own dedicated tests next to this file.
 */
final class Phase3SchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_games_has_started_and_completed_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('games', 'started_at'));
        $this->assertTrue(Schema::hasColumn('games', 'completed_at'));
    }

    public function test_game_draws_table_columns(): void
    {
        $this->assertTrue(Schema::hasTable('game_draws'));
        foreach (['id', 'game_id', 'game_number_id', 'sequence', 'drawn_number', 'drawn_at', 'strategy', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('game_draws', $col), "Missing column game_draws.$col");
        }
        $this->assertFalse(Schema::hasColumn('game_draws', 'updated_at'));
    }

    public function test_game_number_counters_table_columns(): void
    {
        $this->assertTrue(Schema::hasTable('game_number_counters'));
        foreach (['id', 'game_id', 'game_number_id', 'hits_count', 'last_draw_sequence', 'created_at', 'updated_at'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('game_number_counters', $col),
                "Missing column game_number_counters.$col"
            );
        }
    }

    public function test_game_winners_table_columns(): void
    {
        $this->assertTrue(Schema::hasTable('game_winners'));
        foreach (['id', 'game_id', 'game_entry_id', 'game_draw_id', 'game_number_id', 'user_id', 'winning_hits', 'won_at', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('game_winners', $col), "Missing column game_winners.$col");
        }
        $this->assertFalse(Schema::hasColumn('game_winners', 'updated_at'));
    }

    public function test_draw_commands_table_columns(): void
    {
        $this->assertTrue(Schema::hasTable('draw_commands'));
        foreach (['id', 'game_id', 'command_id', 'game_draw_id', 'result_payload', 'completed_at', 'created_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('draw_commands', $col), "Missing column draw_commands.$col");
        }
        $this->assertFalse(Schema::hasColumn('draw_commands', 'updated_at'));
    }
}
