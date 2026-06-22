<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\RebuildGameNumberCountersAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersData;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersOutcome;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\RebuildIntegrityViolation;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RebuildCountersCancelledLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function action(): RebuildGameNumberCountersAction
    {
        return $this->app->make(RebuildGameNumberCountersAction::class);
    }

    private function makeGame(GameStatus $status, ?\DateTimeInterface $startedAt = null, ?\DateTimeInterface $completedAt = null): Game
    {
        $g = Game::create([
            'slug' => 'cn-'.fake()->unique()->lexify('?????'),
            'name' => 'CN', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => $status,
        ]);
        if ($startedAt !== null) {
            $g->started_at = $startedAt;
        }
        if ($completedAt !== null) {
            $g->completed_at = $completedAt;
        }
        $g->saveQuietly();
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create(['game_id' => $g->id, 'number' => $i, 'status' => GameNumberStatus::Available]);
        }

        return $g;
    }

    private function insertDraw(Game $game, int $number, int $sequence, \DateTimeInterface $at): void
    {
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', $number)->firstOrFail();
        DB::table('game_draws')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'sequence' => $sequence,
            'drawn_number' => $number,
            'drawn_at' => $at,
            'strategy' => 'crypto_secure',
            'created_at' => $at,
        ]);
    }

    public function test_cancelled_before_start_with_no_draws_is_consistent(): void
    {
        $game = $this->makeGame(GameStatus::Cancelled);
        $admin = User::factory()->admin()->create();

        $result = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::AlreadyConsistent, $result->outcome);
    }

    public function test_cancelled_before_start_with_draws_is_integrity_violation(): void
    {
        $game = $this->makeGame(GameStatus::Cancelled);
        $this->insertDraw($game, 1, 1, now());
        $admin = User::factory()->admin()->create();

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_cancelled_after_start_with_valid_draws_is_consistent(): void
    {
        $startedAt = now()->subMinutes(10);
        $game = $this->makeGame(GameStatus::Cancelled, $startedAt);
        $this->insertDraw($game, 1, 1, $startedAt->copy()->addSeconds(30));
        $this->insertDraw($game, 2, 2, $startedAt->copy()->addSeconds(60));
        $admin = User::factory()->admin()->create();

        $result = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));

        $this->assertSame(RebuildCountersOutcome::Rebuilt, $result->outcome);
        // Re-running yields AlreadyConsistent — the cancelled lifecycle
        // remains valid.
        $second = $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
        $this->assertSame(RebuildCountersOutcome::AlreadyConsistent, $second->outcome);
    }

    public function test_cancelled_with_completed_at_is_integrity_violation(): void
    {
        $game = $this->makeGame(GameStatus::Cancelled, now()->subHour(), now()->subMinute());
        $admin = User::factory()->admin()->create();

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }

    public function test_cancelled_with_winner_is_integrity_violation(): void
    {
        $startedAt = now()->subHour();
        $game = $this->makeGame(GameStatus::Cancelled, $startedAt);
        $gn = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn->status = GameNumberStatus::Sold;
        $gn->save();
        $buyer = User::factory()->create();
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id, 'user_id' => $buyer->id,
            'status' => EntryStatus::Winner, 'confirmed_at' => $startedAt,
        ]);
        $this->insertDraw($game, 1, 1, $startedAt->copy()->addSecond());
        $drawId = DB::table('game_draws')->where('game_id', $game->id)->value('id');
        GameWinner::create([
            'game_id' => $game->id, 'game_entry_id' => $entry->id,
            'game_draw_id' => $drawId, 'game_number_id' => $gn->id,
            'user_id' => $buyer->id, 'winning_hits' => 1, 'won_at' => $startedAt,
        ]);
        $admin = User::factory()->admin()->create();

        $this->expectException(RebuildIntegrityViolation::class);
        $this->action()->execute(new RebuildCountersData($game->id, $admin->id));
    }
}
