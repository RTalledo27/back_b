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
use App\Modules\RepeatNumberBingo\Domain\Events\GameCompleted;
use App\Modules\RepeatNumberBingo\Domain\Events\GameNumberDrawn;
use App\Modules\RepeatNumberBingo\Domain\Events\GameWinnerDeclared;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameAlreadyCompleted;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameLifecycleIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

final class DrawWinnerResolutionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, User, GameEntry, User, GameEntry, User}
     */
    private function setupGameWithTwoSoldNumbers(int $hitsRequired = 3, int $numberMax = 10): array
    {
        $game = Game::create([
            'slug' => 'win-'.fake()->unique()->lexify('?????'),
            'name' => 'WIN', 'number_min' => 1, 'number_max' => $numberMax, 'hits_required' => $hitsRequired,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= $numberMax; $i++) {
            GameNumber::create([
                'game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available,
            ]);
        }
        $admin = User::factory()->admin()->create();

        $winnerUser = User::factory()->create();
        $gn1 = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn1->status = GameNumberStatus::Sold;
        $gn1->save();
        $winningEntry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id,
            'user_id' => $winnerUser->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $otherUser = User::factory()->create();
        $gn2 = GameNumber::query()->where('game_id', $game->id)->where('number', 2)->firstOrFail();
        $gn2->status = GameNumberStatus::Sold;
        $gn2->save();
        $otherEntry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn2->id,
            'user_id' => $otherUser->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return [$game, $admin, $winningEntry, $winnerUser, $otherEntry, $otherUser];
    }

    /**
     * @param  list<int>  $sequence
     */
    private function actWith(array $sequence): DrawGameNumberAction
    {
        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy($sequence));

        return $this->app->make(DrawGameNumberAction::class);
    }

    public function test_below_threshold_does_not_create_winner(): void
    {
        [$game, $admin, $winningEntry] = $this->setupGameWithTwoSoldNumbers(hitsRequired: 3);
        $action = $this->actWith([1, 1]);

        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
        $result = $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));

        $this->assertFalse($result->winnerCreated);
        $this->assertSame(EntryStatus::Confirmed, $winningEntry->refresh()->status);
        $this->assertSame(0, GameWinner::query()->where('game_id', $game->id)->count());
        $this->assertSame(GameStatus::Running, $game->refresh()->status);
    }

    public function test_threshold_creates_winner_and_completes_the_game(): void
    {
        Event::fake([GameNumberDrawn::class, GameWinnerDeclared::class, GameCompleted::class]);

        [$game, $admin, $winningEntry, $winnerUser, $otherEntry] = $this->setupGameWithTwoSoldNumbers(hitsRequired: 3);
        $action = $this->actWith([1, 1, 1]);

        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
        $result = $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));

        $this->assertTrue($result->winnerCreated);
        $this->assertSame($winningEntry->id, $result->winnerEntryId);
        $this->assertTrue($result->numberIsSold);
        $this->assertSame(3, $result->currentHits);
        $this->assertSame('completed', $result->gameStatus);

        $winner = GameWinner::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame($winningEntry->id, $winner->game_entry_id);
        $this->assertSame($result->drawId, $winner->game_draw_id);
        $this->assertSame($winnerUser->id, $winner->user_id);
        $this->assertSame(3, $winner->winning_hits);
        $game->refresh();
        $draw = GameDraw::query()->where('game_id', $game->id)->whereKey($result->drawId)->firstOrFail();
        $this->assertSame(GameStatus::Completed, $game->status);
        $this->assertNotNull($game->completed_at);

        // Temporal invariants:
        //   drawn_at <= won_at      (extraction precedes resolution)
        //   won_at  =  completed_at (both materialise in the same moment)
        $this->assertTrue(
            $draw->drawn_at->lessThanOrEqualTo($winner->won_at),
            'GameDraw.drawn_at must precede or equal GameWinner.won_at.',
        );
        $this->assertTrue(
            $winner->won_at->equalTo($game->completed_at),
            'GameWinner.won_at must equal Game.completed_at.',
        );

        // Winning entry transitioned; the other one stays confirmed.
        $this->assertSame(EntryStatus::Winner, $winningEntry->refresh()->status);
        $this->assertSame(EntryStatus::Confirmed, $otherEntry->refresh()->status);

        $audits = GameEvent::query()->where('game_id', $game->id)
            ->whereIn('type', [
                GameEventType::WinningNumberDetected,
                GameEventType::WinnerDeclared,
                GameEventType::GameCompleted,
            ])->get();
        $this->assertCount(3, $audits);
        $byType = $audits->keyBy(fn ($e) => $e->type->value);
        foreach (['winning_number_detected', 'winner_declared', 'game_completed'] as $t) {
            $this->assertTrue($byType->has($t), "Missing audit: $t");
        }
        $this->assertArrayNotHasKey('email', $byType['winner_declared']->payload);
        $this->assertArrayNotHasKey('amount', $byType['game_completed']->payload);

        Event::assertDispatched(GameNumberDrawn::class, 3);
        Event::assertDispatched(GameWinnerDeclared::class, 1);
        Event::assertDispatched(GameCompleted::class, 1);
    }

    public function test_command_snapshot_persisted_for_winning_draw(): void
    {
        [$game, $admin] = $this->setupGameWithTwoSoldNumbers(hitsRequired: 2);
        $action = $this->actWith([1, 1]);

        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
        $winnerCmd = new DrawCommandId((string) Str::uuid7());
        $winnerResult = $action->execute(new DrawGameNumberData($game->id, $winnerCmd, $admin->id));

        $cmd = DrawCommand::query()
            ->where('game_id', $game->id)->where('command_id', $winnerCmd->toString())->firstOrFail();
        $this->assertSame($winnerResult->drawId, $cmd->game_draw_id);
        $payload = $cmd->result_payload;
        $this->assertTrue($payload['winner_created']);
        $this->assertSame($winnerResult->winnerEntryId, $payload['winner_entry_id']);
        $this->assertSame('completed', $payload['game_status']);
        $this->assertSame(2, $payload['current_hits']);
    }

    public function test_replay_of_winning_command_preserves_snapshot(): void
    {
        Event::fake([GameNumberDrawn::class, GameWinnerDeclared::class, GameCompleted::class]);

        [$game, $admin, $winningEntry] = $this->setupGameWithTwoSoldNumbers(hitsRequired: 2);
        $action = $this->actWith([1, 1]);
        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));

        $winnerCmd = new DrawCommandId((string) Str::uuid7());
        $original = $action->execute(new DrawGameNumberData($game->id, $winnerCmd, $admin->id));
        $this->assertTrue($original->winnerCreated);

        $replay = $action->execute(new DrawGameNumberData($game->id, $winnerCmd, $admin->id));

        $this->assertTrue($replay->wasReplay);
        $this->assertTrue($replay->winnerCreated);
        $this->assertSame($winningEntry->id, $replay->winnerEntryId);
        $this->assertSame(2, $replay->currentHits);
        $this->assertSame('completed', $replay->gameStatus);
        $this->assertSame($original->drawId, $replay->drawId);
        $this->assertSame($original->drawnAt->toIso8601String(), $replay->drawnAt->toIso8601String());

        // Replay must NOT redispatch.
        Event::assertDispatched(GameWinnerDeclared::class, 1);
        Event::assertDispatched(GameCompleted::class, 1);
    }

    public function test_new_command_after_completed_is_rejected(): void
    {
        [$game, $admin] = $this->setupGameWithTwoSoldNumbers(hitsRequired: 2);
        $action = $this->actWith([1, 1, 5]);

        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));

        $this->assertSame(GameStatus::Completed, $game->refresh()->status);

        $this->expectException(GameAlreadyCompleted::class);
        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
    }

    public function test_unowned_threshold_still_works_in_phase_36(): void
    {
        [$game, $admin] = $this->setupGameWithTwoSoldNumbers(hitsRequired: 3, numberMax: 10);
        $action = $this->actWith([8, 8, 8]); // number 8 is available

        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));

        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::UnownedNumberReachedThreshold)->count(),
        );
        $this->assertSame(GameStatus::Running, $game->refresh()->status);
    }

    public function test_winner_existing_with_running_game_aborts_by_integrity(): void
    {
        [$game, $admin, $winningEntry] = $this->setupGameWithTwoSoldNumbers();

        // Inject an inconsistent state: winner row present, but game stays running.
        $draw = GameDraw::create([
            'game_id' => $game->id, 'game_number_id' => $winningEntry->game_number_id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
        ]);
        GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $winningEntry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $winningEntry->game_number_id,
            'user_id' => $winningEntry->user_id,
            'winning_hits' => 3,
            'won_at' => now(),
        ]);

        $action = $this->actWith([1]);
        $this->expectException(GameLifecycleIntegrityViolation::class);
        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
    }
}
