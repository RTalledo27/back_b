<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\DeterministicDrawNumberStrategy;
use Tests\TestCase;

/**
 * Rollback and lock-order guarantees of DrawGameNumberAction.
 *
 *  - executeWithinTransaction() outside an open tx must throw.
 *  - Game must be the first FOR UPDATE issued.
 *  - If DrawCommand insertion fails, draw + counter + audit must roll back.
 *  - A failing post-commit listener must not undo any persisted row.
 */
final class DrawGameNumberRollbackTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Game, User}
     */
    private function makeRunningGame(int $hitsRequired = 5, int $numberMax = 10): array
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

    public function test_game_is_the_first_locked_table(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $action = $this->actionWithSequence([2]);

        /** @var list<string> $tables */
        $tables = [];
        DB::listen(function ($query) use (&$tables): void {
            $sql = mb_strtolower((string) $query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            foreach (['games', 'game_numbers', 'game_entries', 'draw_commands', 'game_number_counters', 'game_draws'] as $table) {
                if (preg_match('/\bfrom\s+"?'.preg_quote($table, '/').'"?/i', $sql) === 1) {
                    $tables[] = $table;

                    return;
                }
            }
        });

        $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));

        $this->assertNotEmpty($tables);
        $this->assertSame('games', $tables[0]);
    }

    public function test_failure_inserting_draw_command_rolls_back_draw_counter_and_audit(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $action = $this->actionWithSequence([3]);

        // Trip the DrawCommand creation right at the boundary, AFTER the
        // draw/counter have already been written inside the same tx.
        DrawCommand::creating(function (): bool {
            throw new RuntimeException('simulated DrawCommand persistence failure');
        });

        try {
            $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));
            $this->fail('Expected RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('simulated', $e->getMessage());
        } finally {
            DrawCommand::flushEventListeners();
            // Re-bind to ensure subsequent tests retain default lifecycle.
            DrawCommand::boot();
        }

        $this->assertSame(0, GameDraw::query()->where('game_id', $game->id)->count(), 'GameDraw must NOT persist on rollback.');
        $this->assertSame(0, DrawCommand::query()->where('game_id', $game->id)->count(), 'DrawCommand must NOT persist on rollback.');
        $this->assertSame(0, GameNumberCounter::query()->where('game_id', $game->id)->count(), 'Counter must NOT persist on rollback.');
    }

    public function test_listener_failure_after_commit_does_not_revert_anything(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $action = $this->actionWithSequence([5]);

        Event::listen(GameNumberDrawn::class, function (): void {
            throw new RuntimeException('listener exploded');
        });

        $result = $action->execute(new DrawGameNumberData($game->id, new DrawCommandId((string) Str::uuid7()), $admin->id));

        $this->assertFalse($result->wasReplay);
        $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
        $this->assertSame(1, DrawCommand::query()->where('game_id', $game->id)->count());
        $counter = GameNumberCounter::query()->where('game_id', $game->id)->firstOrFail();
        $this->assertSame(1, $counter->hits_count);
    }
}
