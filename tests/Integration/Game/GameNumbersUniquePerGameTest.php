<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class GameNumbersUniquePerGameTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_postgres_unique_constraint_blocks_duplicate_number_within_a_game(): void
    {
        $game = Game::create([
            'slug' => 'rifa-unique-num',
            'name' => 'X',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ]);

        GameNumber::create([
            'game_id' => $game->id,
            'number' => 7,
            'status' => GameNumberStatus::Available,
        ]);

        $this->expectException(QueryException::class);

        GameNumber::create([
            'game_id' => $game->id,
            'number' => 7,
            'status' => GameNumberStatus::Available,
        ]);
    }

    public function test_same_number_in_different_games_is_allowed(): void
    {
        $base = [
            'name' => 'X',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ];

        $g1 = Game::create([...$base, 'slug' => 'rifa-a']);
        $g2 = Game::create([...$base, 'slug' => 'rifa-b']);

        GameNumber::create(['game_id' => $g1->id, 'number' => 7, 'status' => GameNumberStatus::Available]);
        GameNumber::create(['game_id' => $g2->id, 'number' => 7, 'status' => GameNumberStatus::Available]);

        $this->assertSame(2, GameNumber::query()->where('number', 7)->count());
    }
}
