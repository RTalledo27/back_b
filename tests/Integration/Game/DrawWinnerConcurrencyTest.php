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
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Two real PHP processes race to extract the same winning number.
 *
 * To make the outcome deterministic across the production crypto strategy
 * (which the subprocesses use), we narrow the game's number range to
 * [1, 1] so every draw resolves to number 1 — the sold/confirmed number.
 * The counter is pre-seeded to (hits_required - 1) so the next draw is
 * the winner.
 */
final class DrawWinnerConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    /**
     * @return array{Game, User, GameEntry, GameNumber}
     */
    private function setupAlmostWinningGame(): array
    {
        $game = Game::create([
            'slug' => 'wc-'.fake()->unique()->lexify('?????'),
            'name' => 'WC', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 2,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Running,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinute(),
        ]);
        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Sold,
        ]);
        // Materialise the rest of the range; they stay Available.
        for ($i = 2; $i <= 5; $i++) {
            GameNumber::create([
                'game_id' => $game->id, 'number' => $i, 'status' => GameNumberStatus::Available,
            ]);
        }
        $buyer = User::factory()->create();
        $entry = GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => $buyer->id, 'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        $admin = User::factory()->admin()->create();

        // Pre-seed: one prior draw (hits=1), counter at (hits_required - 1).
        $sequence = 1;
        $prior = (string) Str::uuid7();
        DB::table('game_draws')->insert([
            'id' => $prior,
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'sequence' => $sequence,
            'drawn_number' => 1,
            'drawn_at' => now()->subSecond(),
            'strategy' => 'crypto_secure',
            'created_at' => now()->subSecond(),
        ]);
        GameNumberCounter::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'hits_count' => 1, 'last_draw_sequence' => $sequence,
        ]);
        DrawCommand::create([
            'game_id' => $game->id, 'command_id' => (string) Str::uuid7(),
            'game_draw_id' => $prior,
            'result_payload' => ['schema_version' => 1, 'game_id' => $game->id, 'draw_id' => $prior,
                'sequence' => 1, 'drawn_number' => 1, 'game_number_id' => $gn->id,
                'current_hits' => 1, 'hits_required' => 2, 'number_is_sold' => true,
                'winner_created' => false, 'winner_entry_id' => null,
                'game_status' => 'running', 'drawn_at' => now()->subSecond()->toIso8601String()],
            'completed_at' => now()->subSecond(),
        ]);

        return [$game, $admin, $entry, $gn];
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

    public function test_two_processes_with_distinct_command_ids_produce_a_single_winner(): void
    {
        [$game, $admin, $entry] = $this->setupAlmostWinningGame();

        $a = null;
        $b = null;
        try {
            $a = $this->spawnDraw(['GAME_ID' => $game->id, 'COMMAND_ID' => (string) Str::uuid7(), 'ACTOR_USER_ID' => (string) $admin->id, 'STRATEGY' => 'deterministic', 'STRATEGY_SEQUENCE' => '1']);
            $b = $this->spawnDraw(['GAME_ID' => $game->id, 'COMMAND_ID' => (string) Str::uuid7(), 'ACTOR_USER_ID' => (string) $admin->id, 'STRATEGY' => 'deterministic', 'STRATEGY_SEQUENCE' => '1']);

            $a->wait();
            $b->wait();

            $aOut = json_decode(trim($a->getOutput()), true) ?? [];
            $bOut = json_decode(trim($b->getOutput()), true) ?? [];

            // Exactly one process succeeded; the other was rejected by
            // GameAlreadyCompleted after the first one transitioned the
            // game. (The PG lock serialises them.)
            $oks = array_filter([$aOut['ok'] ?? false, $bOut['ok'] ?? false]);
            $this->assertCount(1, $oks, 'Exactly one process must finish OK.');

            $loser = ($aOut['ok'] ?? false) ? $bOut : $aOut;
            $this->assertFalse($loser['ok']);
            $this->assertSame(
                'App\\Modules\\RepeatNumberBingo\\Domain\\Exceptions\\GameAlreadyCompleted',
                $loser['class'],
                'Loser must hit GameAlreadyCompleted, not any integrity violation.',
            );

            $game->refresh();
            $this->assertSame(GameStatus::Completed, $game->status);
            $this->assertNotNull($game->completed_at);

            $this->assertSame(1, GameWinner::query()->where('game_id', $game->id)->count());
            $this->assertSame(EntryStatus::Winner, $entry->refresh()->status);
            $this->assertSame(2, GameDraw::query()->where('game_id', $game->id)->count(), 'One prior + one winning draw.');
            $counter = GameNumberCounter::query()->where('game_id', $game->id)->firstOrFail();
            $this->assertSame(2, $counter->hits_count);

            foreach ([GameEventType::WinningNumberDetected, GameEventType::WinnerDeclared, GameEventType::GameCompleted] as $t) {
                $this->assertSame(1, GameEvent::query()->where('game_id', $game->id)->where('type', $t)->count(), "Audit $t->value count");
            }
        } finally {
            foreach ([$a, $b] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }

    public function test_two_processes_with_same_command_id_produce_a_winner_and_a_replay(): void
    {
        [$game, $admin, $entry] = $this->setupAlmostWinningGame();
        $cmd = (string) Str::uuid7();

        $a = null;
        $b = null;
        try {
            $a = $this->spawnDraw(['GAME_ID' => $game->id, 'COMMAND_ID' => $cmd, 'ACTOR_USER_ID' => (string) $admin->id, 'STRATEGY' => 'deterministic', 'STRATEGY_SEQUENCE' => '1']);
            $b = $this->spawnDraw(['GAME_ID' => $game->id, 'COMMAND_ID' => $cmd, 'ACTOR_USER_ID' => (string) $admin->id, 'STRATEGY' => 'deterministic', 'STRATEGY_SEQUENCE' => '1']);

            $a->wait();
            $b->wait();

            $aOut = json_decode(trim($a->getOutput()), true) ?? [];
            $bOut = json_decode(trim($b->getOutput()), true) ?? [];

            $this->assertTrue($aOut['ok'] ?? false, 'A failed: '.$a->getOutput().$a->getErrorOutput());
            $this->assertTrue($bOut['ok'] ?? false, 'B failed: '.$b->getOutput().$b->getErrorOutput());

            $replays = (int) ($aOut['was_replay'] ?? 0) + (int) ($bOut['was_replay'] ?? 0);
            $this->assertSame(1, $replays);
            $this->assertSame($aOut['draw_id'], $bOut['draw_id']);

            $this->assertSame(GameStatus::Completed, $game->refresh()->status);
            $this->assertSame(1, GameWinner::query()->where('game_id', $game->id)->count());
            $this->assertSame(EntryStatus::Winner, $entry->refresh()->status);
            $this->assertSame(2, GameDraw::query()->where('game_id', $game->id)->count());
            foreach ([GameEventType::WinningNumberDetected, GameEventType::WinnerDeclared, GameEventType::GameCompleted] as $t) {
                $this->assertSame(1, GameEvent::query()->where('game_id', $game->id)->where('type', $t)->count());
            }
        } finally {
            foreach ([$a, $b] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }
}
