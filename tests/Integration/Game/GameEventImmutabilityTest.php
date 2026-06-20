<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class GameEventImmutabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeGame(): Game
    {
        return Game::create([
            'slug' => 'immutable-test',
            'name' => 'Test',
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
    }

    public function test_creating_a_game_event_is_allowed(): void
    {
        $game = $this->makeGame();

        $event = GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::GameCreated,
            'occurred_at' => now(),
        ]);

        $this->assertNotNull($event->id);
        $this->assertSame(GameEventType::GameCreated, $event->type);
    }

    public function test_updating_a_game_event_via_eloquent_throws(): void
    {
        $game = $this->makeGame();
        $event = GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::GameCreated,
            'occurred_at' => now(),
        ]);

        $this->expectException(ImmutableModelException::class);

        $event->update(['payload' => ['tampered' => true]]);
    }

    public function test_assigning_attribute_and_saving_throws(): void
    {
        $game = $this->makeGame();
        $event = GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::GameCreated,
            'occurred_at' => now(),
        ]);

        $event->payload = ['tampered' => true];

        $this->expectException(ImmutableModelException::class);

        $event->save();
    }

    public function test_deleting_a_game_event_via_eloquent_throws(): void
    {
        $game = $this->makeGame();
        $event = GameEvent::create([
            'game_id' => $game->id,
            'type' => GameEventType::GameCreated,
            'occurred_at' => now(),
        ]);

        $this->expectException(ImmutableModelException::class);

        $event->delete();
    }
}
