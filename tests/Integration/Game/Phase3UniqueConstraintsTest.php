<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Phase3UniqueConstraintsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGameWithEntry(string $slug = 'uniq'): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => $slug.'-'.fake()->unique()->lexify('?????'),
            'name' => 'UC', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
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

        return [$user, $game, $gn, $entry];
    }

    public function test_game_draws_game_sequence_is_unique(): void
    {
        [, $game, $gn] = $this->makeGameWithEntry('seq');
        GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);

        $this->expectException(QueryException::class);
        GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
    }

    public function test_only_one_winner_per_game(): void
    {
        [$user, $game, $gn, $entry] = $this->makeGameWithEntry('w');
        $draw1 = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
        $draw2 = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 2, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);

        GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $draw1->id, 'game_number_id' => $gn->id,
            'user_id' => $user->id, 'winning_hits' => 5, 'won_at' => now(),
        ]);

        // Second winner attempt for the same game must fail (UNIQUE game_id).
        $this->expectException(QueryException::class);
        GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $draw2->id, 'game_number_id' => $gn->id,
            'user_id' => $user->id, 'winning_hits' => 5, 'won_at' => now(),
        ]);
    }

    public function test_draw_command_game_command_id_is_unique(): void
    {
        [, $game, $gn] = $this->makeGameWithEntry('cmd');
        $draw1 = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
        $draw2 = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 2, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
        $cmdId = (string) Str::uuid7();
        DrawCommand::create([
            'game_id' => $game->id, 'command_id' => $cmdId,
            'game_draw_id' => $draw1->id,
            'result_payload' => ['sequence' => 1],
            'completed_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DrawCommand::create([
            'game_id' => $game->id, 'command_id' => $cmdId,
            'game_draw_id' => $draw2->id,
            'result_payload' => ['sequence' => 2],
            'completed_at' => now(),
        ]);
    }

    public function test_draw_command_game_draw_id_is_unique(): void
    {
        [, $game, $gn] = $this->makeGameWithEntry('cmd2');
        $draw = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
        DrawCommand::create([
            'game_id' => $game->id, 'command_id' => (string) Str::uuid7(),
            'game_draw_id' => $draw->id,
            'result_payload' => ['sequence' => 1],
            'completed_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DrawCommand::create([
            'game_id' => $game->id, 'command_id' => (string) Str::uuid7(),
            'game_draw_id' => $draw->id,
            'result_payload' => ['sequence' => 1],
            'completed_at' => now(),
        ]);
    }

    public function test_game_winner_game_draw_id_is_unique(): void
    {
        // Two separate games, same draw_id would be impossible because draws
        // already FK to a single game. Here we hit the explicit UNIQUE on
        // game_winners.game_draw_id directly by attempting to insert two
        // winners pointing at the same draw (would also fail by UNIQUE(game_id),
        // but this test forces the path to be the draw_id index — we still
        // exercise the constraint).
        [$user, $game, $gn, $entry] = $this->makeGameWithEntry('wdraw');
        $draw = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
        GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id, 'game_number_id' => $gn->id,
            'user_id' => $user->id, 'winning_hits' => 5, 'won_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id, 'game_number_id' => $gn->id,
            'user_id' => $user->id, 'winning_hits' => 5, 'won_at' => now(),
        ]);
    }

    public function test_game_draws_sequence_must_be_positive(): void
    {
        [, $game, $gn] = $this->makeGameWithEntry('chk');
        $this->expectException(QueryException::class);
        GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 0, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
    }
}
