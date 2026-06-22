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
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Phase3AppendOnlyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function buildContext(): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'ap-'.fake()->unique()->lexify('?????'),
            'name' => 'AP', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 100, 'prize_cents' => 500, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => true,
            'status' => GameStatus::Running,
        ]);
        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => 2, 'status' => GameNumberStatus::Sold,
        ]);
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => $user->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $draw = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 2,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);

        return [$user, $game, $gn, $entry, $draw];
    }

    public function test_game_draw_update_is_blocked(): void
    {
        [, , , , $draw] = $this->buildContext();
        $draw->drawn_number = 99;
        $this->expectException(ImmutableModelException::class);
        $draw->save();
    }

    public function test_game_draw_delete_is_blocked(): void
    {
        [, , , , $draw] = $this->buildContext();
        $this->expectException(ImmutableModelException::class);
        $draw->delete();
    }

    public function test_game_winner_update_is_blocked(): void
    {
        [$user, $game, $gn, $entry, $draw] = $this->buildContext();
        $winner = GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id, 'game_number_id' => $gn->id,
            'user_id' => $user->id, 'winning_hits' => 5, 'won_at' => now(),
        ]);
        $winner->winning_hits = 999;
        $this->expectException(ImmutableModelException::class);
        $winner->save();
    }

    public function test_game_winner_delete_is_blocked(): void
    {
        [$user, $game, $gn, $entry, $draw] = $this->buildContext();
        $winner = GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id, 'game_number_id' => $gn->id,
            'user_id' => $user->id, 'winning_hits' => 5, 'won_at' => now(),
        ]);
        $this->expectException(ImmutableModelException::class);
        $winner->delete();
    }

    public function test_draw_command_update_is_blocked(): void
    {
        [, $game, , , $draw] = $this->buildContext();
        $cmd = DrawCommand::create([
            'game_id' => $game->id, 'command_id' => (string) Str::uuid7(),
            'game_draw_id' => $draw->id,
            'result_payload' => ['sequence' => 1],
            'completed_at' => now(),
        ]);
        $cmd->result_payload = ['tampered' => true];
        $this->expectException(ImmutableModelException::class);
        $cmd->save();
    }

    public function test_draw_command_delete_is_blocked(): void
    {
        [, $game, , , $draw] = $this->buildContext();
        $cmd = DrawCommand::create([
            'game_id' => $game->id, 'command_id' => (string) Str::uuid7(),
            'game_draw_id' => $draw->id,
            'result_payload' => ['sequence' => 1],
            'completed_at' => now(),
        ]);
        $this->expectException(ImmutableModelException::class);
        $cmd->delete();
    }
}
