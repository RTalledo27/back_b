<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cross-game foreign-key protection. These tests bypass the engine Actions
 * (which already validate semantics) and attack the schema directly via
 * raw DB::table()->insert() — the database itself must reject any attempt
 * to reference a row that lives in a different game.
 */
final class Phase3CompositeForeignKeysTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, GameNumber, GameEntry, User}
     */
    private function buildGameAggregate(string $slug, int $userIdMaybe = 0): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => $slug.'-'.fake()->unique()->lexify('?????'),
            'name' => 'X', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 100, 'prize_cents' => 500, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
        ]);
        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold,
        ]);
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => $user->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return [$game, $gn, $entry, $user];
    }

    public function test_game_draw_with_number_from_other_game_is_rejected_by_composite_fk(): void
    {
        [, $gnA] = $this->buildGameAggregate('cfk-a');
        [$gameB] = $this->buildGameAggregate('cfk-b');

        // game_id = B, but game_number_id belongs to A → composite FK fails.
        $this->expectException(QueryException::class);
        DB::table('game_draws')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $gameB->id,
            'game_number_id' => $gnA->id,
            'sequence' => 1,
            'drawn_number' => 1,
            'drawn_at' => now(),
            'strategy' => 'crypto_secure',
            'created_at' => now(),
        ]);
    }

    public function test_game_number_counter_with_number_from_other_game_is_rejected_by_composite_fk(): void
    {
        [, $gnA] = $this->buildGameAggregate('cfkc-a');
        [$gameB] = $this->buildGameAggregate('cfkc-b');

        $this->expectException(QueryException::class);
        DB::table('game_number_counters')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $gameB->id,
            'game_number_id' => $gnA->id,
            'hits_count' => 1,
            'last_draw_sequence' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_game_winner_with_entry_from_other_game_is_rejected_by_composite_fk(): void
    {
        [$gameA, $gnA, $entryA, $userA] = $this->buildGameAggregate('cfkw-a');
        [$gameB, $gnB] = $this->buildGameAggregate('cfkw-b');

        // Create a draw legitimately for game B so the draw-side composite FK
        // would resolve; entry from game A must fail the entry composite FK.
        $drawB = GameDraw::create([
            'game_id' => $gameB->id, 'game_number_id' => $gnB->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);

        $this->expectException(QueryException::class);
        DB::table('game_winners')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $gameB->id,                  // game B
            'game_entry_id' => $entryA->id,            // entry from game A → fails
            'game_draw_id' => $drawB->id,
            'game_number_id' => $gnB->id,
            'user_id' => $userA->id,
            'winning_hits' => 5,
            'won_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function test_game_entry_with_number_from_other_game_is_rejected_by_composite_fk(): void
    {
        [, $gnA] = $this->buildGameAggregate('cfke-a');
        [$gameB] = $this->buildGameAggregate('cfke-b');
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('game_entries')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $gameB->id,
            'game_number_id' => $gnA->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_draw_command_with_draw_from_other_game_is_rejected_by_composite_fk(): void
    {
        [$gameA, $gnA] = $this->buildGameAggregate('cfkd-a');
        [$gameB] = $this->buildGameAggregate('cfkd-b');
        $drawA = GameDraw::create([
            'game_id' => $gameA->id, 'game_number_id' => $gnA->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);

        $this->expectException(QueryException::class);
        DB::table('draw_commands')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $gameB->id,                  // game B
            'command_id' => (string) Str::uuid7(),
            'game_draw_id' => $drawA->id,             // draw from game A → fails
            'result_payload' => json_encode(['x' => 1]),
            'completed_at' => now(),
            'created_at' => now(),
        ]);
    }
}
