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
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Phase3UuidV7Test extends TestCase
{
    use LazilyRefreshDatabase;

    private function uuidVersion(string $uuid): int
    {
        $hex = str_replace('-', '', $uuid);

        // Version nibble is the high nibble of the 7th byte (offset 12-13 in hex).
        return (int) hexdec($hex[12]);
    }

    private function setupGameWithSoldNumber(): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'uuid7-'.fake()->unique()->lexify('?????'),
            'name' => 'U7', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 100, 'prize_cents' => 500, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
        ]);
        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => 3, 'status' => GameNumberStatus::Sold,
        ]);
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => $user->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return [$user, $game, $gn, $entry];
    }

    public function test_game_draw_id_is_uuid_v7(): void
    {
        [, $game, $gn] = $this->setupGameWithSoldNumber();
        $draw = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 3,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
        $this->assertSame(7, $this->uuidVersion($draw->id));
    }

    public function test_game_number_counter_id_is_uuid_v7(): void
    {
        [, $game, $gn] = $this->setupGameWithSoldNumber();
        $counter = GameNumberCounter::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'hits_count' => 1, 'last_draw_sequence' => 1,
        ]);
        $this->assertSame(7, $this->uuidVersion($counter->id));
    }

    public function test_game_winner_id_is_uuid_v7(): void
    {
        [$user, $game, $gn, $entry] = $this->setupGameWithSoldNumber();
        $draw = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 3,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
        $winner = GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id, 'game_number_id' => $gn->id,
            'user_id' => $user->id, 'winning_hits' => 5, 'won_at' => now(),
        ]);
        $this->assertSame(7, $this->uuidVersion($winner->id));
    }

    public function test_draw_command_id_is_uuid_v7(): void
    {
        [, $game, $gn] = $this->setupGameWithSoldNumber();
        $draw = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 3,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
        $cmd = DrawCommand::create([
            'game_id' => $game->id, 'command_id' => (string) Str::uuid7(),
            'game_draw_id' => $draw->id,
            'result_payload' => ['ok' => true],
            'completed_at' => now(),
        ]);
        $this->assertSame(7, $this->uuidVersion($cmd->id));
    }
}
