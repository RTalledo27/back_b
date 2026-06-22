<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\DrawGameNumberAction;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberData;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

/**
 * Real PostgreSQL rollback when the winner-resolution branch fails.
 * Uses a temporary PG trigger to make INSERT INTO game_winners fail —
 * cheaper and more honest than mocking, and exercises real PG behaviour.
 */
final class DrawWinnerRollbackTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, User, GameEntry}
     */
    private function setupWinnerAlmostThere(int $hitsRequired = 2): array
    {
        $game = Game::create([
            'slug' => 'wr-'.fake()->unique()->lexify('?????'),
            'name' => 'WR', 'number_min' => 1, 'number_max' => 5, 'hits_required' => $hitsRequired,
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
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => $buyer->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        $admin = User::factory()->admin()->create();

        $this->app->instance(DrawNumberStrategy::class, new DeterministicDrawNumberStrategy([1, 1]));
        $action = $this->app->make(DrawGameNumberAction::class);
        // First draw — hits=1, below threshold.
        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));

        return [$game, $admin, $entry];
    }

    private function dropTriggerSilently(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (\Throwable) {
            // best-effort teardown
        }
    }

    public function test_failure_inserting_game_winners_rolls_back_the_entire_transaction(): void
    {
        [$game, $admin, $entry] = $this->setupWinnerAlmostThere();

        $draws_before = GameDraw::query()->where('game_id', $game->id)->count();
        $counter = GameNumberCounter::query()->where('game_id', $game->id)->firstOrFail();
        $hits_before = $counter->hits_count;
        $entryStatusBefore = $entry->status;

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION block_game_winners_insert()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'simulated game_winners insert failure';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement('CREATE TRIGGER block_game_winners_insert_t BEFORE INSERT ON game_winners FOR EACH ROW EXECUTE FUNCTION block_game_winners_insert()');

        try {
            $this->expectException(RuntimeException::class);
            try {
                $this->app->make(DrawGameNumberAction::class)->execute(
                    new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id),
                );
            } catch (\Throwable $e) {
                // Rollback expectations — exactly nothing extra persisted.
                $this->assertSame($draws_before, GameDraw::query()->where('game_id', $game->id)->count(), 'GameDraw must NOT persist when winner insertion fails.');
                $this->assertSame(0, GameWinner::query()->where('game_id', $game->id)->count());
                $this->assertSame($entryStatusBefore, $entry->refresh()->status);
                $game->refresh();
                $this->assertSame(GameStatus::Running, $game->status);
                $this->assertNull($game->completed_at);

                $counter->refresh();
                $this->assertSame($hits_before, $counter->hits_count, 'Counter must NOT have been incremented.');

                foreach ([GameEventType::WinningNumberDetected, GameEventType::WinnerDeclared, GameEventType::GameCompleted] as $t) {
                    $this->assertSame(0, GameEvent::query()->where('game_id', $game->id)->where('type', $t)->count());
                }

                // No spurious DrawCommand for the failed draw.
                $this->assertSame(1, DrawCommand::query()->where('game_id', $game->id)->count(), 'Only the first (pre-failure) command must remain.');

                throw new RuntimeException($e->getMessage());
            }
        } finally {
            $this->dropTriggerSilently('DROP TRIGGER IF EXISTS block_game_winners_insert_t ON game_winners');
            $this->dropTriggerSilently('DROP FUNCTION IF EXISTS block_game_winners_insert()');
        }
    }

    public function test_failure_updating_games_completed_at_rolls_back_entirely(): void
    {
        [$game, $admin, $entry] = $this->setupWinnerAlmostThere();

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION block_game_completion()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'completed' THEN
                    RAISE EXCEPTION 'simulated game completion failure';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::statement('CREATE TRIGGER block_game_completion_t BEFORE UPDATE ON games FOR EACH ROW EXECUTE FUNCTION block_game_completion()');

        try {
            $this->expectException(RuntimeException::class);
            try {
                $this->app->make(DrawGameNumberAction::class)->execute(
                    new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id),
                );
            } catch (\Throwable $e) {
                $this->assertSame(0, GameWinner::query()->where('game_id', $game->id)->count());
                $this->assertSame(EntryStatus::Confirmed, $entry->refresh()->status);
                $game->refresh();
                $this->assertSame(GameStatus::Running, $game->status);
                $this->assertNull($game->completed_at);
                $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count(), 'Only the pre-failure draw must remain.');

                throw new RuntimeException($e->getMessage());
            }
        } finally {
            $this->dropTriggerSilently('DROP TRIGGER IF EXISTS block_game_completion_t ON games');
            $this->dropTriggerSilently('DROP FUNCTION IF EXISTS block_game_completion()');
        }
    }
}
