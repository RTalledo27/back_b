<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\RebuildGameNumberCountersAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersData;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\RebuildIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RebuildCountersIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, User}
     */
    private function makeRunningGame(int $hitsRequired = 3, int $numberMax = 5): array
    {
        $game = Game::create([
            'slug' => 'ri-'.fake()->unique()->lexify('?????'),
            'name' => 'RI', 'number_min' => 1, 'number_max' => $numberMax, 'hits_required' => $hitsRequired,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= $numberMax; $i++) {
            GameNumber::create([
                'game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available,
            ]);
        }

        return [$game, User::factory()->admin()->create()];
    }

    private function insertDraw(Game $game, int $number, int $sequence): void
    {
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', $number)->firstOrFail();
        DB::table('game_draws')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'sequence' => $sequence,
            'drawn_number' => $number,
            'drawn_at' => now()->subSeconds(100 - $sequence),
            'strategy' => 'crypto_secure',
            'created_at' => now(),
        ]);
    }

    private function action(): RebuildGameNumberCountersAction
    {
        return $this->app->make(RebuildGameNumberCountersAction::class);
    }

    public function test_gap_in_sequence_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $this->insertDraw($game, 1, 1);
        $this->insertDraw($game, 2, 3); // sequence 2 missing

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_sequence_not_starting_at_one_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $this->insertDraw($game, 1, 2);
        $this->insertDraw($game, 1, 3);

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_game_completed_without_winner_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $this->insertDraw($game, 1, 1);
        $game->status = GameStatus::Resolving;
        $game->saveQuietly();
        $game->status = GameStatus::Completed;
        $game->completed_at = now();
        $game->saveQuietly();

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_game_running_with_winner_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();
        $buyer = User::factory()->create();
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id, 'user_id' => $buyer->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);
        $this->insertDraw($game, 1, 1);
        $drawId = DB::table('game_draws')->where('game_id', $game->id)->value('id');

        // Stash a winner while leaving the game in Running.
        $entry->transitionTo(EntryStatus::Winner);
        $entry->save();
        GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $drawId, 'game_number_id' => $gn->id,
            'user_id' => $buyer->id, 'winning_hits' => 3, 'won_at' => now(),
        ]);

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_winner_draw_not_final_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame(hitsRequired: 2);
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();
        $buyer = User::factory()->create();
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id, 'user_id' => $buyer->id,
            'status' => EntryStatus::Winner, 'confirmed_at' => now(),
        ]);

        // Draws: 1, 1, 1 — number 1 hit 3 times. Winner row will point at
        // the SECOND draw, which is not the final one (sequence != max).
        $this->insertDraw($game, 1, 1);
        $this->insertDraw($game, 1, 2);
        $this->insertDraw($game, 1, 3);
        $secondDrawId = DB::table('game_draws')->where('game_id', $game->id)
            ->where('sequence', 2)->value('id');

        $game->status = GameStatus::Completed;
        $game->completed_at = now();
        $game->saveQuietly();
        GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $secondDrawId, 'game_number_id' => $gn->id,
            'user_id' => $buyer->id, 'winning_hits' => 2, 'won_at' => now(),
        ]);

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_winner_hits_mismatch_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame(hitsRequired: 2);
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();
        $buyer = User::factory()->create();
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id, 'user_id' => $buyer->id,
            'status' => EntryStatus::Winner, 'confirmed_at' => now(),
        ]);

        // Only one draw (history hits=1) but Winner says winning_hits=2.
        $this->insertDraw($game, 1, 1);
        $drawId = DB::table('game_draws')->where('game_id', $game->id)->value('id');
        $game->status = GameStatus::Completed;
        $game->completed_at = now();
        $game->saveQuietly();
        GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $drawId, 'game_number_id' => $gn->id,
            'user_id' => $buyer->id, 'winning_hits' => 2, 'won_at' => now(),
        ]);

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_draws_in_sales_closed_is_integrity_violation(): void
    {
        $game = Game::create([
            'slug' => 'sc-'.fake()->unique()->lexify('?????'),
            'name' => 'SC', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => true,
            'status' => GameStatus::SalesClosed,
        ]);
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create(['game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available]);
        }
        $admin = User::factory()->admin()->create();
        $this->insertDraw($game, 1, 1); // illegal: draw before start

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_running_without_started_at_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $game->started_at = null;
        $game->saveQuietly();

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_running_with_completed_at_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $game->completed_at = now();
        $game->saveQuietly();

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_draw_predating_started_at_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        // Insert a draw whose drawn_at is BEFORE the game's started_at.
        DB::table('game_draws')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'sequence' => 1,
            'drawn_number' => 1,
            'drawn_at' => now()->subHours(2),  // earlier than started_at (subMinute)
            'strategy' => 'crypto_secure',
            'created_at' => now(),
        ]);

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_completed_without_started_at_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $game->status = GameStatus::Completed;
        $game->started_at = null;
        $game->completed_at = now();
        $game->saveQuietly();

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_draw_after_completed_at_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame(hitsRequired: 2);
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();
        $buyer = User::factory()->create();
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id, 'user_id' => $buyer->id,
            'status' => EntryStatus::Winner, 'confirmed_at' => now(),
        ]);

        $completedAt = now()->subMinute();
        $this->insertDraw($game, 1, 1);
        // Last draw AFTER completed_at — illegal.
        DB::table('game_draws')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 2, 'drawn_number' => 1,
            'drawn_at' => $completedAt->copy()->addMinute(),
            'strategy' => 'crypto_secure',
            'created_at' => now(),
        ]);
        $finalDrawId = DB::table('game_draws')->where('game_id', $game->id)
            ->where('sequence', 2)->value('id');

        $game->status = GameStatus::Completed;
        $game->completed_at = $completedAt;
        $game->saveQuietly();
        GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $finalDrawId, 'game_number_id' => $gn->id,
            'user_id' => $buyer->id, 'winning_hits' => 2, 'won_at' => $completedAt,
        ]);

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_winner_won_at_not_equal_to_completed_at_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame(hitsRequired: 2);
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();
        $buyer = User::factory()->create();
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id, 'user_id' => $buyer->id,
            'status' => EntryStatus::Winner, 'confirmed_at' => now(),
        ]);
        $this->insertDraw($game, 1, 1);
        $this->insertDraw($game, 1, 2);
        $winnerDrawId = DB::table('game_draws')->where('game_id', $game->id)
            ->where('sequence', 2)->value('id');

        $completedAt = now()->subSecond();
        $game->status = GameStatus::Completed;
        $game->completed_at = $completedAt;
        $game->saveQuietly();
        // won_at deliberately diverges from completed_at.
        GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $winnerDrawId, 'game_number_id' => $gn->id,
            'user_id' => $buyer->id, 'winning_hits' => 2,
            'won_at' => $completedAt->copy()->addMinute(),
        ]);

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_resolving_state_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $game->status = GameStatus::Resolving;
        $game->saveQuietly();

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_integrity_violation_preserves_existing_projection(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $this->insertDraw($game, 1, 1);
        $this->insertDraw($game, 2, 3); // gap → integrity violation
        $gn1 = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $existing = GameNumberCounter::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id,
            'hits_count' => 9, 'last_draw_sequence' => 9,
        ]);

        try {
            $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
            $this->fail('Expected RebuildIntegrityViolation');
        } catch (RebuildIntegrityViolation) {
            // expected
        }

        $existing->refresh();
        $this->assertSame(9, $existing->hits_count, 'Existing (bad) counter must be preserved across rollback.');
    }
}
