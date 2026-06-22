<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\RebuildGameNumberCountersAction;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersData;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameCountersRebuilt;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Behaviour assertions for the transactional contract of
 * RebuildGameNumberCountersAction.
 */
final class RebuildCountersTransactionTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    /**
     * @return array{Game, User}
     */
    private function makeRunningGameWithSingleDraw(): array
    {
        $game = Game::create([
            'slug' => 'rt-'.fake()->unique()->lexify('?????'),
            'name' => 'RT', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
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
        DB::table('game_draws')->insert([
            'id' => (string) Str::uuid7(),
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'sequence' => 1, 'drawn_number' => 1,
            'drawn_at' => now(), 'strategy' => 'crypto_secure',
            'created_at' => now(),
        ]);

        return [$game, User::factory()->admin()->create()];
    }

    public function test_within_transaction_outside_transaction_throws(): void
    {
        [$game, $admin] = $this->makeRunningGameWithSingleDraw();

        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(LogicException::class);
        $this->app->make(RebuildGameNumberCountersAction::class)->executeWithinTransaction(
            new RebuildCountersData($game->id, $admin->id),
        );
    }

    public function test_game_is_the_first_locked_table(): void
    {
        [$game, $admin] = $this->makeRunningGameWithSingleDraw();

        /** @var list<string> $tables */
        $tables = [];
        DB::listen(function ($query) use (&$tables): void {
            $sql = mb_strtolower((string) $query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            foreach (['games', 'game_number_counters', 'game_draws'] as $t) {
                if (preg_match('/\bfrom\s+"?'.preg_quote($t, '/').'"?/i', $sql) === 1) {
                    $tables[] = $t;

                    return;
                }
            }
        });

        $this->app->make(RebuildGameNumberCountersAction::class)->execute(
            new RebuildCountersData($game->id, $admin->id),
        );

        $this->assertNotEmpty($tables);
        $this->assertSame('games', $tables[0]);
    }

    public function test_listener_failure_after_commit_does_not_revert(): void
    {
        [$game, $admin] = $this->makeRunningGameWithSingleDraw();

        Event::listen(GameCountersRebuilt::class, function (): void {
            throw new RuntimeException('listener exploded');
        });

        $this->app->make(RebuildGameNumberCountersAction::class)->execute(
            new RebuildCountersData($game->id, $admin->id),
        );

        // Projection persisted, audit row persisted.
        $this->assertSame(1, GameNumberCounter::query()->where('game_id', $game->id)->count());
        $this->assertSame(
            1,
            DB::table('game_events')->where('game_id', $game->id)
                ->where('type', 'counters_rebuilt')->count(),
        );
    }
}
