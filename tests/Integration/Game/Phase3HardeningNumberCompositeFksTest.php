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
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 hardening — these tests attack the database directly with raw
 * DB::table()->insert() to confirm the new three-column composite FKs
 * reject any "wrong number" scenario, not just "wrong game".
 */
final class Phase3HardeningNumberCompositeFksTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, GameNumber, GameNumber, GameEntry, User}
     */
    private function buildGameWithTwoSoldNumbers(string $slug): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => $slug.'-'.fake()->unique()->lexify('?????'),
            'name' => 'H', 'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 100, 'prize_cents' => 500, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
        ]);
        $gn1 = GameNumber::create([
            'game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold,
        ]);
        $gn2 = GameNumber::create([
            'game_id' => $game->id, 'number' => 2, 'status' => GameNumberStatus::Sold,
        ]);
        $entryOnGn1 = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id,
            'user_id' => $user->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return [$game, $gn1, $gn2, $entryOnGn1, $user];
    }

    public function test_game_draw_with_mismatching_drawn_number_is_rejected(): void
    {
        [$game, $gn1] = $this->buildGameWithTwoSoldNumbers('mismatch');

        // game_number_id points at row with number=1, but drawn_number says 2.
        $this->expectException(QueryException::class);
        DB::table('game_draws')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $game->id,
            'game_number_id' => $gn1->id,
            'sequence' => 1,
            'drawn_number' => 2,
            'drawn_at' => now(),
            'strategy' => 'crypto_secure',
            'created_at' => now(),
        ]);
    }

    public function test_game_draw_with_matching_drawn_number_is_accepted(): void
    {
        [$game, $gn1] = $this->buildGameWithTwoSoldNumbers('ok');

        $draw = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
        $this->assertNotNull($draw->id);
    }

    public function test_winner_with_entry_and_draw_pointing_at_different_numbers_is_rejected(): void
    {
        [$game, $gn1, $gn2, $entryOnGn1, $user] = $this->buildGameWithTwoSoldNumbers('mix');

        // Draw for game_number gn2 (number 2) — different from the entry's gn1.
        $drawOnGn2 = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn2->id,
            'sequence' => 1, 'drawn_number' => 2,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);

        $this->expectException(QueryException::class);
        DB::table('game_winners')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $game->id,
            'game_entry_id' => $entryOnGn1->id,      // entry on gn1
            'game_draw_id' => $drawOnGn2->id,        // draw on gn2
            'game_number_id' => $gn1->id,            // pick one — still inconsistent with the draw
            'user_id' => $user->id,
            'winning_hits' => 5,
            'won_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function test_winner_with_game_number_not_matching_entry_is_rejected(): void
    {
        [$game, $gn1, $gn2, $entryOnGn1, $user] = $this->buildGameWithTwoSoldNumbers('ent');

        // Build a valid draw for gn2 so the draw-side composite FK could
        // theoretically resolve; the entry-side FK must still fail because
        // entry is on gn1 but winner.game_number_id is gn2.
        $drawOnGn2 = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn2->id,
            'sequence' => 1, 'drawn_number' => 2,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);

        $this->expectException(QueryException::class);
        DB::table('game_winners')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $game->id,
            'game_entry_id' => $entryOnGn1->id,      // entry on gn1
            'game_draw_id' => $drawOnGn2->id,        // draw on gn2
            'game_number_id' => $gn2->id,            // matches draw, NOT entry → entry FK fails
            'user_id' => $user->id,
            'winning_hits' => 5,
            'won_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function test_winner_with_game_number_not_matching_draw_is_rejected(): void
    {
        [$game, $gn1, $gn2, $entryOnGn1, $user] = $this->buildGameWithTwoSoldNumbers('drw');

        $drawOnGn1 = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);

        $this->expectException(QueryException::class);
        DB::table('game_winners')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $game->id,
            'game_entry_id' => $entryOnGn1->id,      // entry on gn1
            'game_draw_id' => $drawOnGn1->id,        // draw on gn1
            'game_number_id' => $gn2->id,            // mismatches both → draw FK fails first
            'user_id' => $user->id,
            'winning_hits' => 5,
            'won_at' => now(),
            'created_at' => now(),
        ]);
    }

    public function test_valid_winner_with_full_alignment_is_accepted(): void
    {
        [$game, $gn1, , $entryOnGn1, $user] = $this->buildGameWithTwoSoldNumbers('val');

        $drawOnGn1 = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);

        $winner = GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entryOnGn1->id,
            'game_draw_id' => $drawOnGn1->id,
            'game_number_id' => $gn1->id,
            'user_id' => $user->id,
            'winning_hits' => 5,
            'won_at' => now(),
        ]);

        $this->assertNotNull($winner->id);
        $this->assertSame($entryOnGn1->id, $winner->game_entry_id);
        $this->assertSame($drawOnGn1->id, $winner->game_draw_id);
        $this->assertSame($gn1->id, $winner->game_number_id);
    }
}
