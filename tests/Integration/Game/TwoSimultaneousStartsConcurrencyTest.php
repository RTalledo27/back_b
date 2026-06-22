<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real two-process concurrency: each PHP process opens its own PostgreSQL
 * connection. The Game row lock serialises them. One must observe a fresh
 * Started outcome; the other an idempotent AlreadyStarted.
 *
 * Uses DatabaseTruncation so the rows inserted from the test are visible
 * to the spawned processes (RefreshDatabase wraps the test in a
 * transaction the children would never see).
 */
final class TwoSimultaneousStartsConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Subprocesses INSERT rows outside any Laravel-managed transaction.
     * If the next test in the suite uses LazilyRefreshDatabase (which
     * does NOT truncate on setUp) it would see leftovers. Explicitly
     * truncate after our work so the surface stays clean.
     */
    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    private function makeReadyGame(): array
    {
        $game = Game::create([
            'slug' => 'tsc-'.fake()->unique()->lexify('?????'),
            'name' => 'TSC', 'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->subMinute(),
        ]);
        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold,
        ]);
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();
        GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => $buyer->id,
            'status' => EntryStatus::Confirmed, 'confirmed_at' => now(),
        ]);

        return [$game, $admin];
    }

    private function startProcess(string $gameId, int $userId): Process
    {
        $base = base_path();
        $config = config('database.connections.pgsql');

        $process = new Process([
            PHP_BINARY,
            $base.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'run-engine-action.php',
            'start',
            'GAME_ID='.$gameId,
            'ACTOR_USER_ID='.$userId,
        ]);
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
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    public function test_two_simultaneous_starts_produce_one_started_and_one_already_started(): void
    {
        [$game, $admin] = $this->makeReadyGame();

        $a = null;
        $b = null;
        try {
            $a = $this->startProcess($game->id, $admin->id);
            $b = $this->startProcess($game->id, $admin->id);

            $a->wait();
            $b->wait();

            $this->assertSame(0, $a->getExitCode(), 'Process A failed: '.$a->getOutput().$a->getErrorOutput());
            $this->assertSame(0, $b->getExitCode(), 'Process B failed: '.$b->getOutput().$b->getErrorOutput());

            $aOut = json_decode(trim($a->getOutput()), true);
            $bOut = json_decode(trim($b->getOutput()), true);
            $this->assertIsArray($aOut);
            $this->assertIsArray($bOut);

            $outcomes = [$aOut['outcome'], $bOut['outcome']];
            sort($outcomes);
            $this->assertSame(['already_started', 'started'], $outcomes);

            $this->assertSame($aOut['started_at'], $bOut['started_at']);

            $game->refresh();
            $this->assertSame(GameStatus::Running, $game->status);
            $this->assertNotNull($game->started_at);
            $this->assertSame(
                1,
                GameEvent::query()->where('game_id', $game->id)
                    ->where('type', GameEventType::GameStarted)->count(),
            );
        } finally {
            foreach ([$a, $b] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }
}
