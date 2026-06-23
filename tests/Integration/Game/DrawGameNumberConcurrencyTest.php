<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\DrawCommand;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real two-process concurrency for DrawGameNumberAction. Each subprocess
 * opens its own PostgreSQL connection and competes for the Game FOR
 * UPDATE lock. The subprocesses use the production crypto strategy â€” we
 * verify structural invariants (sequence monotonicity, no duplicates,
 * single command per id) rather than the specific drawn number.
 */
final class DrawGameNumberConcurrencyTest extends TestCase
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
    private function makeRunningGame(int $hitsRequired = 5, int $numberMax = 10): array
    {
        $game = Game::create([
            'slug' => 'dc-'.fake()->unique()->lexify('?????'),
            'name' => 'DC', 'number_min' => 1, 'number_max' => $numberMax, 'hits_required' => $hitsRequired,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::Running,
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
     * @param  array<string, string>  $args
     */
    private function spawnDraw(array $args): Process
    {
        $base = base_path();
        $config = config('database.connections.pgsql');

        $cli = [PHP_BINARY, $base.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'run-engine-action.php', 'draw'];
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
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    public function test_two_processes_with_distinct_command_ids_produce_consecutive_sequences(): void
    {
        [$game, $admin] = $this->makeRunningGame();

        $cmdA = (string) Str::uuid7();
        $cmdB = (string) Str::uuid7();

        $a = null;
        $b = null;
        try {
            $a = $this->spawnDraw(['GAME_ID' => $game->id, 'COMMAND_ID' => $cmdA, 'ACTOR_USER_ID' => (string) $admin->id]);
            $b = $this->spawnDraw(['GAME_ID' => $game->id, 'COMMAND_ID' => $cmdB, 'ACTOR_USER_ID' => (string) $admin->id]);

            $a->wait();
            $b->wait();

            $this->assertSame(0, $a->getExitCode(), 'A: '.$a->getOutput().$a->getErrorOutput());
            $this->assertSame(0, $b->getExitCode(), 'B: '.$b->getOutput().$b->getErrorOutput());

            $aOut = json_decode(trim($a->getOutput()), true);
            $bOut = json_decode(trim($b->getOutput()), true);
            $this->assertIsArray($aOut);
            $this->assertIsArray($bOut);

            $this->assertFalse($aOut['was_replay']);
            $this->assertFalse($bOut['was_replay']);

            $sequences = [$aOut['sequence'], $bOut['sequence']];
            sort($sequences);
            $this->assertSame([1, 2], $sequences, 'Sequences must be consecutive without gaps.');

            $this->assertSame(2, GameDraw::query()->where('game_id', $game->id)->count());
            $this->assertSame(2, DrawCommand::query()->where('game_id', $game->id)->count());
        } finally {
            foreach ([$a, $b] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }

    public function test_two_processes_with_same_command_id_produce_a_single_draw_and_a_replay(): void
    {
        [$game, $admin] = $this->makeRunningGame();
        $cmd = (string) Str::uuid7();

        $a = null;
        $b = null;
        try {
            $a = $this->spawnDraw(['GAME_ID' => $game->id, 'COMMAND_ID' => $cmd, 'ACTOR_USER_ID' => (string) $admin->id]);
            $b = $this->spawnDraw(['GAME_ID' => $game->id, 'COMMAND_ID' => $cmd, 'ACTOR_USER_ID' => (string) $admin->id]);

            $a->wait();
            $b->wait();

            $this->assertSame(0, $a->getExitCode(), 'A: '.$a->getOutput().$a->getErrorOutput());
            $this->assertSame(0, $b->getExitCode(), 'B: '.$b->getOutput().$b->getErrorOutput());

            $aOut = json_decode(trim($a->getOutput()), true);
            $bOut = json_decode(trim($b->getOutput()), true);

            $replays = (int) $aOut['was_replay'] + (int) $bOut['was_replay'];
            $this->assertSame(1, $replays, 'Exactly one process must report wasReplay=true.');

            // Both processes describe the same persisted draw.
            $this->assertSame($aOut['draw_id'], $bOut['draw_id']);
            $this->assertSame($aOut['sequence'], $bOut['sequence']);
            $this->assertSame($aOut['drawn_number'], $bOut['drawn_number']);
            $this->assertSame($aOut['drawn_at'], $bOut['drawn_at']);

            $this->assertSame(1, GameDraw::query()->where('game_id', $game->id)->count());
            $this->assertSame(1, DrawCommand::query()->where('game_id', $game->id)->count());
            $counter = GameNumberCounter::query()->where('game_id', $game->id)->firstOrFail();
            $this->assertSame(1, $counter->hits_count);
        } finally {
            foreach ([$a, $b] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }
}
