<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Services\GameNumberGenerator;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\BingoNumberRange;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class Uuid7GenerationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * UUID layout: xxxxxxxx-xxxx-Vxxx-yxxx-xxxxxxxxxxxx
     * The version digit is at index 14 (0-indexed) in the canonical string.
     */
    private function uuidVersionOf(string $uuid): int
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $uuid,
            "Not a canonical UUID: {$uuid}"
        );

        return (int) hexdec($uuid[14]);
    }

    public function test_game_id_is_uuid_v7(): void
    {
        $game = Game::create([
            'slug' => 'uuid7-game',
            'name' => 'X',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ]);

        $this->assertSame(7, $this->uuidVersionOf($game->id));
    }

    public function test_generated_game_numbers_are_all_uuid_v7_and_unique(): void
    {
        $game = Game::create([
            'slug' => 'uuid7-numbers',
            'name' => 'X',
            'number_min' => 1,
            'number_max' => 30,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ]);

        (new GameNumberGenerator)->generateFor(
            $game,
            new BingoNumberRange(1, 30, 5),
        );

        $ids = GameNumber::query()->where('game_id', $game->id)->pluck('id');

        $this->assertSame(30, $ids->count(), '30 numbers must have been inserted.');
        $this->assertSame($ids->count(), $ids->unique()->count(), 'All ids must be unique.');

        foreach ($ids as $id) {
            $this->assertSame(7, $this->uuidVersionOf($id), "Number id {$id} is not UUID v7.");
        }
    }

    public function test_game_event_id_is_uuid_v7(): void
    {
        $game = Game::create([
            'slug' => 'uuid7-event',
            'name' => 'X',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ]);

        $event = GameEvent::create([
            'game_id' => $game->id,
            'type' => \App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType::GameCreated,
            'occurred_at' => now(),
        ]);

        $this->assertSame(7, $this->uuidVersionOf($event->id));
    }
}
