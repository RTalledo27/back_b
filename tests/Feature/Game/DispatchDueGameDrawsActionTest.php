<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Modules\RepeatNumberBingo\Application\Actions\DispatchDueGameDrawsAction;
use App\Modules\RepeatNumberBingo\Application\Jobs\DispatchDueGameDrawsJob;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

final class DispatchDueGameDrawsActionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function makeDueGame(?CarbonImmutable $nextDrawAt = null): Game
    {
        return Game::create([
            'slug' => 'dd-'.fake()->unique()->lexify('?????'),
            'name' => 'DD', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => CarbonImmutable::now()->subHour(),
            'started_at' => CarbonImmutable::now()->subMinutes(10),
            'next_draw_at' => $nextDrawAt ?? CarbonImmutable::now()->subSeconds(5),
        ]);
    }

    // -------------------------------------------------------------------------
    // Eligibility filters
    // -------------------------------------------------------------------------

    public function test_selects_eligible_due_games(): void
    {
        $game = $this->makeDueGame();

        $result = app(DispatchDueGameDrawsAction::class)->execute();

        $this->assertCount(1, $result->ticks);
        $this->assertSame($game->id, $result->ticks[0]->gameId);
    }

    public function test_ignores_manual_games_alongside_eligible_ones(): void
    {
        $eligible = $this->makeDueGame();
        $manual = $this->makeDueGame();
        $manual->auto_draw_enabled = false;
        $manual->saveQuietly();

        $result = app(DispatchDueGameDrawsAction::class)->execute();

        $selectedIds = array_map(fn (EngineTick $t) => $t->gameId, $result->ticks);
        $this->assertContains($eligible->id, $selectedIds);
        $this->assertNotContains($manual->id, $selectedIds);
    }

    public function test_ignores_paused_games(): void
    {
        $eligible = $this->makeDueGame();
        $paused = $this->makeDueGame();
        $paused->status = GameStatus::Paused;
        $paused->paused_at = now();
        $paused->next_draw_at = null;
        $paused->saveQuietly();

        $result = app(DispatchDueGameDrawsAction::class)->execute();

        $selectedIds = array_map(fn (EngineTick $t) => $t->gameId, $result->ticks);
        $this->assertContains($eligible->id, $selectedIds);
        $this->assertNotContains($paused->id, $selectedIds);
    }

    public function test_ignores_completed_games(): void
    {
        $eligible = $this->makeDueGame();
        $completed = $this->makeDueGame();
        $completed->status = GameStatus::Completed;
        $completed->completed_at = now();
        $completed->next_draw_at = null;
        $completed->saveQuietly();

        $result = app(DispatchDueGameDrawsAction::class)->execute();

        $selectedIds = array_map(fn (EngineTick $t) => $t->gameId, $result->ticks);
        $this->assertContains($eligible->id, $selectedIds);
        $this->assertNotContains($completed->id, $selectedIds);
    }

    public function test_ignores_games_with_null_next_draw_at(): void
    {
        $eligible = $this->makeDueGame();
        $noSchedule = $this->makeDueGame();
        $noSchedule->next_draw_at = null;
        $noSchedule->saveQuietly();

        $result = app(DispatchDueGameDrawsAction::class)->execute();

        $selectedIds = array_map(fn (EngineTick $t) => $t->gameId, $result->ticks);
        $this->assertContains($eligible->id, $selectedIds);
        $this->assertNotContains($noSchedule->id, $selectedIds);
    }

    public function test_ignores_games_with_future_next_draw_at(): void
    {
        $eligible = $this->makeDueGame();
        $future = $this->makeDueGame(nextDrawAt: CarbonImmutable::now()->addMinute());

        $result = app(DispatchDueGameDrawsAction::class)->execute();

        $selectedIds = array_map(fn (EngineTick $t) => $t->gameId, $result->ticks);
        $this->assertContains($eligible->id, $selectedIds);
        $this->assertNotContains($future->id, $selectedIds);
    }

    // -------------------------------------------------------------------------
    // Deterministic order: next_draw_at ASC, id ASC
    // -------------------------------------------------------------------------

    public function test_returns_ticks_ordered_by_next_draw_at_asc(): void
    {
        $game3 = $this->makeDueGame(nextDrawAt: CarbonImmutable::now()->subSeconds(1));
        $game1 = $this->makeDueGame(nextDrawAt: CarbonImmutable::now()->subSeconds(30));
        $game2 = $this->makeDueGame(nextDrawAt: CarbonImmutable::now()->subSeconds(15));

        $result = app(DispatchDueGameDrawsAction::class)->execute();

        $this->assertCount(3, $result->ticks);
        $this->assertSame($game1->id, $result->ticks[0]->gameId);
        $this->assertSame($game2->id, $result->ticks[1]->gameId);
        $this->assertSame($game3->id, $result->ticks[2]->gameId);
    }

    // -------------------------------------------------------------------------
    // Does not modify the database
    // -------------------------------------------------------------------------

    public function test_does_not_write_to_database(): void
    {
        $game = $this->makeDueGame();
        $originalNextDrawAt = $game->next_draw_at->toIso8601String();

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            $upper = strtoupper(ltrim($query->sql));
            if (
                str_starts_with($upper, 'INSERT') ||
                str_starts_with($upper, 'UPDATE') ||
                str_starts_with($upper, 'DELETE')
            ) {
                $writes[] = $query->sql;
            }
        });

        app(DispatchDueGameDrawsAction::class)->execute();

        $game->refresh();
        $this->assertSame($originalNextDrawAt, $game->next_draw_at->toIso8601String(), 'next_draw_at must not change');
        $this->assertSame(GameStatus::Running, $game->status, 'status must not change');
        $this->assertEmpty($writes, 'No write SQL must be issued: '.implode(', ', $writes));
    }

    // -------------------------------------------------------------------------
    // Batch size
    // -------------------------------------------------------------------------

    public function test_respects_batch_size_config(): void
    {
        Config::set('engine.dispatch_batch_size', 2);

        for ($i = 0; $i < 5; $i++) {
            $this->makeDueGame(nextDrawAt: CarbonImmutable::now()->subSeconds($i + 1));
        }

        $result = app(DispatchDueGameDrawsAction::class)->execute();

        $this->assertCount(2, $result->ticks);
        $this->assertSame(2, $result->candidatesFound);
    }

    // -------------------------------------------------------------------------
    // EngineTick shape
    // -------------------------------------------------------------------------

    public function test_tick_carries_scheduled_at_from_persisted_next_draw_at(): void
    {
        $expectedAt = CarbonImmutable::now()->subSeconds(10);
        $game = $this->makeDueGame(nextDrawAt: $expectedAt);

        $result = app(DispatchDueGameDrawsAction::class)->execute();

        $this->assertCount(1, $result->ticks);
        $this->assertSame($game->id, $result->ticks[0]->gameId);
        $this->assertEqualsWithDelta(
            $expectedAt->timestamp,
            $result->ticks[0]->scheduledAt->timestamp,
            1,
        );
    }

    public function test_tick_command_id_is_deterministic_across_calls(): void
    {
        $this->makeDueGame(nextDrawAt: CarbonImmutable::now()->subSeconds(10));

        $r1 = app(DispatchDueGameDrawsAction::class)->execute();
        $r2 = app(DispatchDueGameDrawsAction::class)->execute();

        $this->assertSame(
            $r1->ticks[0]->commandId->value,
            $r2->ticks[0]->commandId->value,
            'UUID v5 must produce the same command ID for the same game + scheduledAt',
        );
    }

    // -------------------------------------------------------------------------
    // candidatesFound
    // -------------------------------------------------------------------------

    public function test_candidates_found_matches_batch_limited_candidate_count(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->makeDueGame(nextDrawAt: CarbonImmutable::now()->subSeconds($i + 1));
        }

        $result = app(DispatchDueGameDrawsAction::class)->execute();

        $this->assertSame(3, $result->candidatesFound);
        $this->assertCount(3, $result->ticks);
    }

    // -------------------------------------------------------------------------
    // Poll-seconds config validation
    // -------------------------------------------------------------------------

    public function test_validate_poll_seconds_rejects_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DispatchDueGameDrawsJob::validatePollSeconds(0);
    }

    public function test_validate_poll_seconds_rejects_sixty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DispatchDueGameDrawsJob::validatePollSeconds(60);
    }

    public function test_validate_poll_seconds_rejects_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DispatchDueGameDrawsJob::validatePollSeconds(-5);
    }

    public function test_validate_poll_seconds_rejects_values_not_in_allowed_set(): void
    {
        // 3, 4, 6, 7, 12, 59 are not in the allowed set {1,2,5,10,15,20,30}.
        foreach ([3, 4, 6, 7, 12, 59] as $bad) {
            try {
                DispatchDueGameDrawsJob::validatePollSeconds($bad);
                $this->fail("Expected InvalidArgumentException for {$bad}");
            } catch (InvalidArgumentException) {
                // expected
            }
        }
        $this->addToAssertionCount(6);
    }

    public function test_validate_poll_seconds_accepts_all_valid_values(): void
    {
        foreach (DispatchDueGameDrawsJob::VALID_POLL_SECONDS as $seconds) {
            DispatchDueGameDrawsJob::validatePollSeconds($seconds);
        }
        $this->addToAssertionCount(count(DispatchDueGameDrawsJob::VALID_POLL_SECONDS));
    }
}
