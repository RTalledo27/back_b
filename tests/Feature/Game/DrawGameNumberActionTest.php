<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameNumberDrawn;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameAlreadyCompleted;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameParticipationIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

final class DrawGameNumberActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Builds a running game with `number_max` pre-materialised game_numbers
     * (mirrors what PublishGameAction does in production) and an admin.
     *
     * @return array{Game, User}
     */
    private function makeRunningGame(int $hitsRequired = 5, int $numberMax = 10): array
    {
        $game = Game::create([
            'slug' => 'dr-'.fake()->unique()->lexify('?????'),
            'name' => 'DR', 'number_min' => 1, 'number_max' => $numberMax, 'hits_required' => $hitsRequired,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::Running,
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

    private function sellNumber(Game $game, int $number): GameEntry
    {
        $gn = GameNumber::query()
            ->where('game_id', $game->id)->where('number', $number)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();

        return GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);
    }

    /**
     * Run the action with a deterministic Strategy bound into the container.
     *
     * @param  list<int>  $sequence
     */
    private function actWithSequence(array $sequence): DrawGameNumberAction
    {
        $this->app->instance(
            DrawNumberStrategy::class,
            new DeterministicDrawNumberStrategy($sequence),
        );

        return $this->app->make(DrawGameNumberAction::class);
    }

    private function commandId(): DrawCommandId
    {
        return new DrawCommandId((string) Str::uuid7());
    }

    public function test_draws_an_available_number(): void
    {
        Event::fake([GameNumberDrawn::class]);
        [$game, $admin] = $this->makeRunningGame();

        $action = $this->actWithSequence([4]);
        $cmd = $this->commandId();

        $result = $action->execute(new DrawGameNumberData($game->id, $cmd, $admin->id));

        $this->assertSame(4, $result->drawnNumber);
        $this->assertSame(1, $result->sequence);
        $this->assertSame(1, $result->currentHits);
        $this->assertFalse($result->numberIsSold);
        $this->assertFalse($result->winnerCreated);
        $this->assertNull($result->winnerEntryId);
        $this->assertSame('running', $result->gameStatus);
        $this->assertFalse($result->wasReplay);

        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame(1, DrawCommand::query()->where('game_id', $game->id)->count());
        $counter = GameNumberCounter::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame(1, $counter->hits_count);
        $this->assertSame(1, $counter->last_draw_sequence);

        Event::assertDispatched(GameNumberDrawn::class, 1);
    }

    public function test_draws_a_sold_number_below_threshold(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $this->sellNumber($game, 7);

        $action = $this->actWithSequence([7]);
        $result = $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));

        $this->assertSame(7, $result->drawnNumber);
        $this->assertTrue($result->numberIsSold);
        $this->assertFalse($result->winnerCreated);
        $this->assertSame(1, $result->currentHits);
    }

    public function test_repeats_numbers_and_increments_sequence_without_gaps(): void
    {
        [$game, $admin] = $this->makeRunningGame(numberMax: 10);
        $action = $this->actWithSequence([3, 3, 5, 3]);

        for ($i = 0; $i < 4; $i++) {
            $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));
        }

        $sequences = GameDraw::query()->where('game_id', $game->id)
            ->orderBy('sequence')->pluck('sequence')->all();
        $this->assertSame([1, 2, 3, 4], $sequences);

        $counterForThree = GameNumberCounter::query()
            ->where('game_id', $game->id)
            ->whereIn('game_number_id', GameNumber::query()
                ->where('game_id', $game->id)->where('number', 3)->pluck('id'))
            ->firstOrFail();
        $this->assertSame(3, $counterForThree->hits_count);
        $this->assertSame(4, $counterForThree->last_draw_sequence);
    }

    public function test_unowned_audit_fires_exactly_once_when_threshold_is_reached(): void
    {
        [$game, $admin] = $this->makeRunningGame(hitsRequired: 5);
        $action = $this->actWithSequence([2, 2, 2, 2, 2, 2, 2]); // 7 draws of number 2

        for ($i = 0; $i < 7; $i++) {
            $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));
        }

        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);

        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::UnownedNumberReachedThreshold)->count(),
            'Unowned-threshold audit must fire only on the exact equality.',
        );

        $counter = GameNumberCounter::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame(7, $counter->hits_count);
    }

    public function test_reserved_number_in_running_game_aborts_by_integrity(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 4)->firstOrFail();
        $gn->status = GameNumberStatus::Reserved;
        $gn->save();

        $action = $this->actWithSequence([4]);
        $this->expectException(GameParticipationIntegrityViolation::class);
        $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));

        $this->assertSame(0, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame(0, DrawCommand::query()->where('game_id', $game->id)->count());
    }

    public function test_sold_number_without_entry_aborts_by_integrity(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 5)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();

        $action = $this->actWithSequence([5]);
        $this->expectException(GameParticipationIntegrityViolation::class);
        $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));
    }

    public function test_available_number_with_an_entry_aborts_by_integrity(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 8)->firstOrFail();
        // Status left Available, but a confirmed entry is forced in.
        GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);

        $action = $this->actWithSequence([8]);
        $this->expectException(GameParticipationIntegrityViolation::class);
        $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));
    }

    public function test_sold_with_non_confirmed_entry_aborts_by_integrity(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $entry = $this->sellNumber($game, 6);
        $entry->transitionTo(EntryStatus::Cancelled);
        $entry->save();

        $action = $this->actWithSequence([6]);
        $this->expectException(GameParticipationIntegrityViolation::class);
        $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));
    }

    public function test_game_not_running_is_rejected(): void
    {
        $game = Game::create([
            'slug' => 'dr-nr-'.fake()->unique()->lexify('?????'),
            'name' => 'NR', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000, 'currency' => 'PEN',
            'draw_interval_seconds' => 30, 'auto_draw_enabled' => false,
            'status' => GameStatus::SalesClosed,
        ]);
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create([
                'game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available,
            ]);
        }

        $admin = User::factory()->admin()->create();
        $action = $this->actWithSequence([1]);

        $this->expectException(InvalidGameTransition::class);
        $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));
    }

    public function test_running_without_started_at_is_integrity_violation(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $game->started_at = null;
        $game->saveQuietly();

        $action = $this->actWithSequence([1]);
        $this->expectException(GameLifecycleIntegrityViolation::class);
        $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));
    }

    public function test_completed_game_raises_already_completed(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $game->status = GameStatus::Resolving;
        $game->saveQuietly();
        $game->status = GameStatus::Completed;
        $game->completed_at = now();
        $game->saveQuietly();

        $action = $this->actWithSequence([1]);
        $this->expectException(GameAlreadyCompleted::class);
        $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));
    }

    public function test_counter_above_threshold_with_running_game_aborts_by_integrity(): void
    {
        // Phase 3.6 guard: a sold number with a confirmed entry must never
        // reach hits_count > hits_required while the game is still running.
        // Reaching exactly hits_required is the winner path; > is corruption.
        //
        // Force the corruption by pre-seeding a counter at hits_required and
        // then drawing one more time. This bypasses the natural game-state
        // transition path so we can exercise the guard in isolation.
        [$game, $admin] = $this->makeRunningGame(hitsRequired: 3);
        $this->sellNumber($game, 4);

        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 4)->firstOrFail();
        GameNumberCounter::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'hits_count' => 3, 'last_draw_sequence' => 999,
        ]);

        $action = $this->actWithSequence([4]);
        $this->expectException(GameParticipationIntegrityViolation::class);
        $action->execute(new DrawGameNumberData($game->id, $this->commandId(), $admin->id));
    }
}
