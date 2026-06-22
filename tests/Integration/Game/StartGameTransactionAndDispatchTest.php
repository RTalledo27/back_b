<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\StartGameAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameData;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameStarted;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Uses DatabaseTruncation (not LazilyRefreshDatabase) because one test
 * exercises behaviour at DB::transactionLevel() === 0 — the wrapping
 * test transaction that RefreshDatabase would open hides the assertion.
 */
final class StartGameTransactionAndDispatchTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    private function makeReadyGame(): Game
    {
        $game = Game::create([
            'slug' => 'st-'.fake()->unique()->lexify('?????'),
            'name' => 'ST', 'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
        ]);
        $gn = GameNumber::create(['game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold]);
        GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);

        return $game;
    }

    public function test_execute_within_transaction_outside_transaction_throws_logic_exception(): void
    {
        $game = $this->makeReadyGame();
        $admin = User::factory()->admin()->create();

        $this->expectException(LogicException::class);
        $this->app->make(StartGameAction::class)->executeWithinTransaction(
            new StartGameData($game->id, $admin->id),
        );
    }

    public function test_game_is_the_first_locked_table(): void
    {
        $game = $this->makeReadyGame();
        $admin = User::factory()->admin()->create();

        /** @var list<string> $tables */
        $tables = [];
        DB::listen(function ($query) use (&$tables): void {
            $sql = mb_strtolower((string) $query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            foreach (['games', 'game_events', 'game_entries', 'game_numbers'] as $table) {
                if (preg_match('/\bfrom\s+"?'.preg_quote($table, '/').'"?/i', $sql) === 1) {
                    $tables[] = $table;

                    return;
                }
            }
        });

        $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $admin->id),
        );

        $this->assertNotEmpty($tables, 'No FOR UPDATE queries captured.');
        $this->assertSame('games', $tables[0], 'Game must be the first locked table.');
    }

    public function test_listener_failure_after_commit_does_not_revert_the_start(): void
    {
        $game = $this->makeReadyGame();
        $admin = User::factory()->admin()->create();

        Event::listen(GameStarted::class, function (): void {
            throw new RuntimeException('listener exploded');
        });

        // execute() wraps the dispatch in try/catch+report; the action
        // must NOT propagate the listener exception nor roll back.
        $result = $this->app->make(StartGameAction::class)->execute(
            new StartGameData($game->id, $admin->id),
        );

        $game->refresh();
        $this->assertSame(GameStatus::Running, $game->status);
        $this->assertNotNull($game->started_at);
        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::GameStarted)->count(),
        );
        $this->assertSame('started', $result->outcome->value);
    }
}
