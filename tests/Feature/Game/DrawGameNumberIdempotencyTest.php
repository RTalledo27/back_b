<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameNumberDrawn;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

final class DrawGameNumberIdempotencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, User}
     */
    private function makeRunningGame(int $hitsRequired = 5, int $numberMax = 10): array
    {
        $game = Game::create([
            'slug' => 'di-'.fake()->unique()->lexify('?????'),
            'name' => 'DI', 'number_min' => 1, 'number_max' => $numberMax, 'hits_required' => $hitsRequired,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= $numberMax; $i++) {
            GameNumber::create([
                'game_id' => $game->id, 'number' => $i,
                'status' => GameNumberStatus::Available,
            ]);
        }

        return [$game, User::factory()->admin()->create()];
    }

    /**
     * @param  list<int>  $sequence
     */
    private function actionWithSequence(array $sequence): DrawGameNumberAction
    {
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy($sequence));

        return $this->app->make(DrawGameNumberAction::class);
    }

    public function test_same_command_id_replays_the_same_draw(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $action = $this->actionWithSequence([7]);
        $cmd = new DrawCommandId((string) Str::uuid7());

        $first = $action->execute(new DrawGameNumberData($game->id, $cmd, $admin->id));
        $second = $action->execute(new DrawGameNumberData($game->id, $cmd, $admin->id));

        $this->assertFalse($first->wasReplay);
        $this->assertTrue($second->wasReplay);

        // Historic values preserved.
        $this->assertSame($first->drawId, $second->drawId);
        $this->assertSame($first->sequence, $second->sequence);
        $this->assertSame($first->drawnNumber, $second->drawnNumber);
        $this->assertSame($first->currentHits, $second->currentHits);
        $this->assertSame($first->drawnAt->toIso8601String(), $second->drawnAt->toIso8601String());

        // No side effects on replay.
        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame(1, DrawCommand::query()->where('game_id', $game->id)->count());
        $counter = GameNumberCounter::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame(1, $counter->hits_count);
    }

    public function test_replay_preserves_historic_hits_even_after_later_draws_of_the_same_number(): void
    {
        Event::fake([GameNumberDrawn::class]);
        [$game, $admin] = $this->makeRunningGame(hitsRequired: 6);
        $action = $this->actionWithSequence([4, 4, 4]);

        $cmdFirst = new DrawCommandId((string) Str::uuid7());
        $first = $action->execute(new DrawGameNumberData($game->id, $cmdFirst, $admin->id));
        $this->assertSame(1, $first->currentHits);

        // Two more draws of number 4 via different command ids.
        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));

        $counter = GameNumberCounter::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame(3, $counter->hits_count);

        $replay = $action->execute(new DrawGameNumberData($game->id, $cmdFirst, $admin->id));
        $this->assertTrue($replay->wasReplay);
        $this->assertSame(1, $replay->currentHits, 'Replay must report the historic hits, not the current counter.');
        $this->assertSame($first->drawId, $replay->drawId);
        $this->assertSame($first->drawnAt->toIso8601String(), $replay->drawnAt->toIso8601String());

        Event::assertDispatched(GameNumberDrawn::class, 3); // only the three fresh draws
    }

    public function test_different_command_ids_create_new_draws(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $action = $this->actionWithSequence([2, 5]);

        $first = $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
        $second = $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));

        $this->assertNotSame($first->drawId, $second->drawId);
        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
        $this->assertSame(2, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame(2, DrawCommand::query()->where('game_id', $game->id)->count());
    }
}
