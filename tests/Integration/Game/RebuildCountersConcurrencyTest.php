<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
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
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real two-process concurrency: a rebuild and a draw race on the Game
 * root lock. Either order is acceptable; the final state must always
 * be coherent (no lost increments, no spurious counters, no duplicate
 * audits).
 */
final class RebuildCountersConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    /**
     * @return array{Game, User, GameNumber}
     */
    private function setupGameWithCorruptProjection(): array
    {
        $game = Game::create([
            'slug' => 'rc-'.fake()->unique()->lexify('?????'),
            'name' => 'RC', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 10,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
        for ($i = 1; $i <= 5; $i++) {
            GameNumber::create([
                'game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available,
            ]);
        }

        // Sell number 1 so a future Draw can land here without
        // accidentally reaching the winner branch.
        $gn1 = GameNumber::query()->where('game_id', $game->id)->where('number', 1)->firstOrFail();
        $gn1->status = GameNumberStatus::Sold;
        $gn1->save();
        $buyer = User::factory()->create();
        GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id,
            'user_id' => $buyer->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        // Seed two prior draws of number 1 (so hits expected = 2,
        // below hits_required=10).
        for ($s = 1; $s <= 2; $s++) {
            DB::table('game_draws')->insert([
                'id' => (string) Str::uuid7(),
                'game_id' => $game->id, 'game_number_id' => $gn1->id,
                'sequence' => $s, 'drawn_number' => 1,
                'drawn_at' => now()->subSeconds(10 - $s), 'strategy' => 'crypto_secure',
                'created_at' => now(),
            ]);
        }

        // Insert a wrong counter so rebuild has work to do. hits_count must stay
        // below hits_required (10) so a draw-first ordering does not trip the
        // integrity guard (currentHits > hits_required) before rebuild corrects it.
        GameNumberCounter::create([
            'game_id' => $game->id, 'game_number_id' => $gn1->id,
            'hits_count' => 1, 'last_draw_sequence' => 999,
        ]);

        return [$game, User::factory()->admin()->create(), $gn1];
    }

    /**
     * @param  array<string, string>  $args
     */
    private function spawn(string $action, array $args): Process
    {
        $base = base_path();
        $config = config('database.connections.pgsql');
        $cli = [PHP_BINARY, $base.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'run-engine-action.php', $action];
        foreach ($args as $k => $v) {
            $cli[] = $k.'='.$v;
        }
        $process = new Process($cli);
        $process->setWorkingDirectory($base);
        $process->setEnv([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $config['host'],
            'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => $config['database'],
            'DB_USERNAME' => $config['username'],
            'DB_PASSWORD' => $config['password'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
        ]);
        $process->setTimeout(60);
        $process->start();

        return $process;
    }

    public function test_concurrent_rebuild_and_draw_serialise_through_game_lock(): void
    {
        [$game, $admin, $gn1] = $this->setupGameWithCorruptProjection();

        $rebuild = null;
        $draw = null;
        try {
            $rebuild = $this->spawn('rebuild', ['GAME_ID' => $game->id, 'ACTOR_USER_ID' => (string) $admin->id]);
            $draw = $this->spawn('draw', [
                'GAME_ID' => $game->id,
                'COMMAND_ID' => (string) Str::uuid7(),
                'ACTOR_USER_ID' => (string) $admin->id,
                'STRATEGY' => 'deterministic',
                'STRATEGY_SEQUENCE' => '1',
            ]);

            $rebuild->wait();
            $draw->wait();

            $this->assertSame(0, $rebuild->getExitCode(), 'Rebuild failed: '.$rebuild->getOutput().$rebuild->getErrorOutput());
            $this->assertSame(0, $draw->getExitCode(), 'Draw failed: '.$draw->getOutput().$draw->getErrorOutput());

            // History now has three draws of number 1.
            $this->assertSame(3, GameDraw::query()->where('game_id', $game->id)->count());
            $counter = GameNumberCounter::query()->where('game_id', $game->id)
                ->where('game_number_id', $gn1->id)->firstOrFail();
            // Counter must reflect EVERY draw, no matter which process won
            // the lock first.
            $this->assertSame(3, $counter->hits_count, 'Counter must reflect every committed draw.');
            $this->assertSame(3, $counter->last_draw_sequence);

            // No duplicate counters.
            $this->assertSame(
                1,
                GameNumberCounter::query()->where('game_id', $game->id)
                    ->where('game_number_id', $gn1->id)->count(),
                'No duplicate counter for the same number.',
            );

            // Exactly one CountersRebuilt audit (only one rebuild ran).
            $this->assertSame(
                1,
                GameEvent::query()->where('game_id', $game->id)
                    ->where('type', GameEventType::CountersRebuilt)->count(),
            );

            // One new draw command persisted.
            $this->assertSame(1, DrawCommand::query()->where('game_id', $game->id)->count());
        } finally {
            foreach ([$rebuild, $draw] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }
}
