<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Belt-and-braces around the "one Winner entry per game" invariant. The
 * partial unique index game_entries_one_winner_per_game must reject a
 * second entry transitioning to Winner in the same game even if a future
 * code path bypassed the Action's validation.
 */
final class Phase3SingleWinnerEntryIndexTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_partial_unique_index_blocks_two_winner_entries_in_same_game(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $game = Game::create([
            'slug' => 'pwidx-'.fake()->unique()->lexify('?????'),
            'name' => 'PW', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 100, 'prize_cents' => 500, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
        ]);
        $gnA = GameNumber::create([
            'game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold,
        ]);
        $gnB = GameNumber::create([
            'game_id' => $game->id, 'number' => 2, 'status' => GameNumberStatus::Sold,
        ]);
        $entryA = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gnA->id,
            'user_id' => $userA->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        $entryB = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gnB->id,
            'user_id' => $userB->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $entryA->transitionTo(EntryStatus::Winner);
        $entryA->save();

        // Second winner in the same game must fail the partial unique index.
        $entryB->transitionTo(EntryStatus::Winner);
        $this->expectException(QueryException::class);
        $entryB->save();
    }

    public function test_partial_unique_index_allows_one_winner_per_distinct_game(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $gameA = Game::create([
            'slug' => 'pw-a-'.fake()->unique()->lexify('?????'),
            'name' => 'PWA', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 100, 'prize_cents' => 500, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
        ]);
        $gameB = Game::create([
            'slug' => 'pw-b-'.fake()->unique()->lexify('?????'),
            'name' => 'PWB', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 100, 'prize_cents' => 500, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
        ]);
        $gnA = GameNumber::create([
            'game_id' => $gameA->id, 'number' => 1, 'status' => GameNumberStatus::Sold,
        ]);
        $gnB = GameNumber::create([
            'game_id' => $gameB->id, 'number' => 1, 'status' => GameNumberStatus::Sold,
        ]);
        $entryA = GameEntry::create([
            'game_id' => $gameA->id, 'game_number_id' => $gnA->id,
            'user_id' => $userA->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        $entryB = GameEntry::create([
            'game_id' => $gameB->id, 'game_number_id' => $gnB->id,
            'user_id' => $userB->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $entryA->transitionTo(EntryStatus::Winner);
        $entryA->save();
        $entryB->transitionTo(EntryStatus::Winner);
        $entryB->save();

        $this->assertSame(EntryStatus::Winner, $entryA->refresh()->status);
        $this->assertSame(EntryStatus::Winner, $entryB->refresh()->status);
    }
}
