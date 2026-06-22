<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\RebuildGameNumberCountersAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersData;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersOutcome;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameCountersRebuilt;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RebuildCountersActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, User}
     */
    private function makeRunningGameWithDraws(int $hitsRequired = 5, int $numberMax = 5): array
    {
        $game = Game::create([
            'slug' => 'rb-'.fake()->unique()->lexify('?????'),
            'name' => 'RB', 'number_min' => 1, 'number_max' => $numberMax, 'hits_required' => $hitsRequired,
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

        return [$game, User::factory()->admin()->create()];
    }

    /**
     * Insert raw draws + matching counter rows. Returns the game's
     * canonical projection.
     *
     * @param  list<int>  $drawnNumbers
     */
    private function seedHistory(Game $game, array $drawnNumbers): void
    {
        $sequence = 0;
        foreach ($drawnNumbers as $number) {
            $sequence++;
            $gn = GameNumber::query()->where('game_id', $game->id)->where('number', $number)->firstOrFail();
            DB::table('game_draws')->insert([
                'id' => (string) Str::uuid7(),
                'game_id' => $game->id,
                'game_number_id' => $gn->id,
                'sequence' => $sequence,
                'drawn_number' => $number,
                'drawn_at' => now()->subSeconds(count($drawnNumbers) - $sequence + 1),
                'strategy' => 'crypto_secure',
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Build the projection from scratch to match the seeded history.
     */
    private function seedCorrectCounters(Game $game): void
    {
        $rows = DB::table('game_draws')
            ->where('game_id', $game->id)
            ->select('game_number_id', DB::raw('COUNT(*) AS hits_count'), DB::raw('MAX(sequence) AS last_draw_sequence'))
            ->groupBy('game_number_id')
            ->get();
        foreach ($rows as $r) {
            GameNumberCounter::create([
                'game_id' => $game->id,
                'game_number_id' => $r->game_number_id,
                'hits_count' => (int) $r->hits_count,
                'last_draw_sequence' => (int) $r->last_draw_sequence,
            ]);
        }
    }

    private function action(): RebuildGameNumberCountersAction
    {
        return $this->app->make(RebuildGameNumberCountersAction::class);
    }

    public function test_empty_history_with_empty_projection_is_already_consistent(): void
    {
        Event::fake([GameCountersRebuilt::class]);
        [$game, $admin] = $this->makeRunningGameWithDraws();

        $result = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::AlreadyConsistent, $result->outcome);
        $this->assertSame(0, $result->totalDraws);
        $this->assertSame(
            0,
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::CountersRebuilt)->count(),
        );
        Event::assertNotDispatched(GameCountersRebuilt::class);
    }

    public function test_empty_history_with_stale_counters_rebuilds_to_zero_rows(): void
    {
        [$game, $admin] = $this->makeRunningGameWithDraws();
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        GameNumberCounter::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'hits_count' => 3, 'last_draw_sequence' => 5,
        ]);

        $result = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::Rebuilt, $result->outcome);
        $this->assertSame(0, $result->rebuiltRows);
        $this->assertSame(0, GameNumberCounter::query()->where('game_id', $game->id)->count());
    }

    public function test_correct_projection_is_already_consistent(): void
    {
        [$game, $admin] = $this->makeRunningGameWithDraws();
        $this->seedHistory($game, [1, 2, 1, 3, 1]);
        $this->seedCorrectCounters($game);

        $result = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::AlreadyConsistent, $result->outcome);
        $this->assertSame(5, $result->totalDraws);
    }

    public function test_missing_counter_is_rebuilt(): void
    {
        [$game, $admin] = $this->makeRunningGameWithDraws();
        $this->seedHistory($game, [1, 2, 1, 3, 1]);
        // Insert counters only for numbers 1 and 2.
        $gn1 = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn2 = GameNumber::query()->where('game_id', $game->id)->where('number', 2)->firstOrFail();
        GameNumberCounter::create(['game_id' => $game->id, 'game_number_id' => $gn1->id, 'hits_count' => 3, 'last_draw_sequence' => 5]);
        GameNumberCounter::create(['game_id' => $game->id, 'game_number_id' => $gn2->id, 'hits_count' => 1, 'last_draw_sequence' => 2]);

        $result = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::Rebuilt, $result->outcome);
        $this->assertSame(3, $result->rebuiltRows);
    }

    public function test_extra_counter_is_rebuilt(): void
    {
        [$game, $admin] = $this->makeRunningGameWithDraws();
        $this->seedHistory($game, [1, 1]);
        $this->seedCorrectCounters($game);
        // Inject a spurious counter for number 2.
        $gn2 = GameNumber::query()->where('game_id', $game->id)->where('number', 2)->firstOrFail();
        GameNumberCounter::create(['game_id' => $game->id, 'game_number_id' => $gn2->id, 'hits_count' => 99, 'last_draw_sequence' => 99]);

        $result = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::Rebuilt, $result->outcome);
        $this->assertSame(1, $result->rebuiltRows);
        $this->assertSame(1, GameNumberCounter::query()->where('game_id', $game->id)->count());
    }

    public function test_wrong_hits_count_is_rebuilt(): void
    {
        [$game, $admin] = $this->makeRunningGameWithDraws();
        $this->seedHistory($game, [1, 1, 1]);
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        GameNumberCounter::create(['game_id' => $game->id, 'game_number_id' => $gn->id, 'hits_count' => 2, 'last_draw_sequence' => 3]);

        $result = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::Rebuilt, $result->outcome);
        $counter = GameNumberCounter::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame(3, $counter->hits_count);
        $this->assertSame(3, $counter->last_draw_sequence);
    }

    public function test_wrong_last_draw_sequence_is_rebuilt(): void
    {
        [$game, $admin] = $this->makeRunningGameWithDraws();
        $this->seedHistory($game, [1, 2, 1]);
        $this->seedCorrectCounters($game);
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $counter = GameNumberCounter::query()->where('game_id', $game->id)->where('game_number_id', $gn->id)->firstOrFail();
        $counter->last_draw_sequence = 1;
        $counter->save();

        $result = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::Rebuilt, $result->outcome);
        $rebuilt = GameNumberCounter::query()->where('game_id', $game->id)->where('game_number_id', $gn->id)->firstOrFail();
        $this->assertSame(3, $rebuilt->last_draw_sequence);
    }

    public function test_same_total_but_different_assignment_is_rebuilt(): void
    {
        [$game, $admin] = $this->makeRunningGameWithDraws();
        $this->seedHistory($game, [1, 1, 2]); // expect 1=2, 2=1
        $gn1 = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn2 = GameNumber::query()->where('game_id', $game->id)->where('number', 2)->firstOrFail();
        // Same total (3) but swapped: 1=1, 2=2.
        GameNumberCounter::create(['game_id' => $game->id, 'game_number_id' => $gn1->id, 'hits_count' => 1, 'last_draw_sequence' => 2]);
        GameNumberCounter::create(['game_id' => $game->id, 'game_number_id' => $gn2->id, 'hits_count' => 2, 'last_draw_sequence' => 3]);

        $result = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::Rebuilt, $result->outcome);
        $c1 = GameNumberCounter::query()->where('game_id', $game->id)->where('game_number_id', $gn1->id)->firstOrFail();
        $c2 = GameNumberCounter::query()->where('game_id', $game->id)->where('game_number_id', $gn2->id)->firstOrFail();
        $this->assertSame(2, $c1->hits_count);
        $this->assertSame(1, $c2->hits_count);
    }

    public function test_second_run_is_already_consistent(): void
    {
        Event::fake([GameCountersRebuilt::class]);
        [$game, $admin] = $this->makeRunningGameWithDraws();
        $this->seedHistory($game, [1, 2, 1]);
        $gn1 = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        GameNumberCounter::create(['game_id' => $game->id, 'game_number_id' => $gn1->id, 'hits_count' => 1, 'last_draw_sequence' => 1]);

        $first = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
        $second = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::Rebuilt, $first->outcome);
        $this->assertSame(RebuildCountersOutcome::AlreadyConsistent, $second->outcome);
        Event::assertDispatched(GameCountersRebuilt::class, 1);
        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::CountersRebuilt)->count(),
        );
    }

    public function test_audit_payload_contains_expected_metrics_only(): void
    {
        [$game, $admin] = $this->makeRunningGameWithDraws();
        $this->seedHistory($game, [1, 1]);

        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $event = GameEvent::query()->where('game_id', $game->id)
            ->where('type', GameEventType::CountersRebuilt)->firstOrFail();
        $p = $event->payload;
        $this->assertSame($admin->id, $p['actor_user_id']);
        $this->assertSame(0, $p['previous_rows']);
        $this->assertSame(0, $p['previous_hits_total']);
        $this->assertSame(1, $p['rebuilt_rows']);
        $this->assertSame(2, $p['rebuilt_hits_total']);
        $this->assertSame(2, $p['total_draws']);
        $this->assertSame(2, $p['max_sequence']);
        foreach (['email', 'name', 'phone', 'amount', 'price', 'document_path'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $p);
        }
    }

    public function test_rebuild_does_not_change_game_state_or_canonical_data(): void
    {
        [$game, $admin] = $this->makeRunningGameWithDraws();
        $this->seedHistory($game, [1, 2]);
        $statusBefore = $game->status;
        $startedAtBefore = $game->started_at?->toIso8601String();
        $completedAtBefore = $game->completed_at?->toIso8601String();
        $drawsBefore = GameDraw::query()->where('game_id', $game->id)->count();
        $commandsBefore = DrawCommand::query()->where('game_id', $game->id)->count();
        $entriesBefore = GameEntry::query()->where('game_id', $game->id)->count();

        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $game->refresh();
        $this->assertSame($statusBefore, $game->status);
        $this->assertSame($startedAtBefore, $game->started_at?->toIso8601String());
        $this->assertSame($completedAtBefore, $game->completed_at?->toIso8601String());
        $this->assertSame($drawsBefore, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame($commandsBefore, DrawCommand::query()->where('game_id', $game->id)->count());
        $this->assertSame($entriesBefore, GameEntry::query()->where('game_id', $game->id)->count());
    }
}
