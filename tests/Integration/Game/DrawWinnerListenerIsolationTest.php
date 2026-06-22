<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameCompleted;
use App\Modules\RepeatNumberBingo\Domain\Events\GameNumberDrawn;
use App\Modules\RepeatNumberBingo\Domain\Events\GameWinnerDeclared;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

/**
 * The three post-commit dispatches (GameNumberDrawn, GameWinnerDeclared,
 * GameCompleted) must be isolated from each other. A throwing listener on
 * one MUST NOT prevent the next dispatch attempts, and must never roll
 * back the winner / completion / draw / counter / command rows.
 */
final class DrawWinnerListenerIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, User}
     */
    private function setupWinningContext(): array
    {
        $game = Game::create([
            'slug' => 'li-'.fake()->unique()->lexify('?????'),
            'name' => 'LI', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create([
                'game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available,
            ]);
        }
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();
        $buyer = User::factory()->create();
        GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => $buyer->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $admin = User::factory()->admin()->create();
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1, 1]));

        // First draw: hits=1, below threshold.
        $this->app->make(DrawGameNumberAction::class)->execute(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id),
        );

        return [$game, $admin];
    }

    public function test_failure_in_game_number_drawn_listener_still_invokes_winner_and_completed(): void
    {
        [$game, $admin] = $this->setupWinningContext();

        $winnerCalled = false;
        $completedCalled = false;
        Event::listen(GameNumberDrawn::class, function () {
            throw new RuntimeException('drawn listener exploded');
        });
        Event::listen(GameWinnerDeclared::class, function () use (&$winnerCalled) {
            $winnerCalled = true;
        });
        Event::listen(GameCompleted::class, function () use (&$completedCalled) {
            $completedCalled = true;
        });

        $this->app->make(DrawGameNumberAction::class)->execute(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id),
        );

        $this->assertTrue($winnerCalled, 'GameWinnerDeclared must still be dispatched even if the previous listener failed.');
        $this->assertTrue($completedCalled, 'GameCompleted must still be dispatched even if earlier listeners failed.');

        $game->refresh();
        $this->assertSame(GameStatus::Completed, $game->status);
        $this->assertSame(1, GameWinner::query()->where('game_id', $game->id)->count());
        $this->assertSame(2, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame(2, DrawCommand::query()->where('game_id', $game->id)->count());
    }

    public function test_failure_in_winner_listener_still_invokes_completed(): void
    {
        [$game, $admin] = $this->setupWinningContext();

        $completedCalled = false;
        Event::listen(GameWinnerDeclared::class, function () {
            throw new RuntimeException('winner listener exploded');
        });
        Event::listen(GameCompleted::class, function () use (&$completedCalled) {
            $completedCalled = true;
        });

        $this->app->make(DrawGameNumberAction::class)->execute(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id),
        );

        $this->assertTrue($completedCalled);
        $game->refresh();
        $this->assertSame(GameStatus::Completed, $game->status);
    }

    public function test_failure_in_completed_listener_still_persists_everything(): void
    {
        [$game, $admin] = $this->setupWinningContext();

        Event::listen(GameCompleted::class, function () {
            throw new RuntimeException('completed listener exploded');
        });

        $this->app->make(DrawGameNumberAction::class)->execute(
            new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id),
        );

        $game->refresh();
        $this->assertSame(GameStatus::Completed, $game->status);
        $this->assertSame(1, GameWinner::query()->where('game_id', $game->id)->count());
    }
}
