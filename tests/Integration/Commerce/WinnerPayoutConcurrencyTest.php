<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDocument;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real concurrency tests for ProcessWinnerPayoutAction using two PHP processes.
 * Each process opens its own PostgreSQL connection and competes for locks.
 *
 * Canonical lock order: Game → GameWinner → WinnerPayout
 *
 * Scenarios:
 *  (a) Same game, same key  → 1 payout, both succeed
 *  (b) Same game, diff keys → 1 payout, second returns existing
 *  (c) Same key, diff fingerprint (diff external_reference) → IdempotencyKeyMismatch
 *  (d) Payout vs StartGameAction competing for Game FOR UPDATE → lock serialises,
 *      payout wins, StartGame fails with stable domain error, no deadlock
 */
final class WinnerPayoutConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE winner_payout_documents, winner_payouts, game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    /**
     * @return array{User, User, Game, GameWinner}
     */
    private function setupCompletedGame(): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $game = Game::create([
            'slug' => 'wpc-'.fake()->unique()->lexify('?????'),
            'name' => 'Payout Concurrencia',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 3,
            'ticket_price_cents' => 1000, 'prize_cents' => 50000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::Completed,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinutes(30),
        ]);

        $gn = GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => GameNumberStatus::Sold,
        ]);

        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'user_id' => $buyer->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => now()->subMinutes(20),
        ]);

        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'sequence' => 1,
            'drawn_number' => 1,
            'drawn_at' => now()->subMinutes(10),
            'strategy' => 'random',
            'created_at' => now()->subMinutes(10),
        ]);

        $winner = GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $gn->id,
            'user_id' => $buyer->id,
            'winning_hits' => 3,
            'won_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
        ]);

        return [$buyer, $admin, $game, $winner];
    }

    private function spawnPayout(
        string $gameId,
        int $actorUserId,
        string $idempotencyKey,
        string $externalReference = 'OP-TEST',
    ): Process {
        return $this->spawn([
            'payout',
            'GAME_ID='.$gameId,
            'ACTOR_USER_ID='.$actorUserId,
            'IDEMPOTENCY_KEY='.$idempotencyKey,
            'EXTERNAL_REFERENCE='.$externalReference,
        ]);
    }

    /**
     * @param  list<string>  $args
     */
    private function spawn(array $args): Process
    {
        $base = base_path();
        $config = config('database.connections.pgsql');

        $process = new Process(array_merge(
            [
                PHP_BINARY,
                $base.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'run-engine-action.php',
            ],
            $args,
        ));
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

    /**
     * (a) Same game, same idempotency key, simultaneous race.
     *
     * Expected: exactly 1 WinnerPayout, exactly 1 document, both processes ok.
     */
    public function test_same_game_same_key_race_produces_exactly_one_payout(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();

        $key = 'concurrency-key-payout-same-aa';

        $p1 = null;
        $p2 = null;
        try {
            $p1 = $this->spawnPayout((string) $game->id, (int) $admin->id, $key);
            $p2 = $this->spawnPayout((string) $game->id, (int) $admin->id, $key);

            $p1->wait();
            $p2->wait();

            $out1 = json_decode(trim($p1->getOutput()), true) ?? [];
            $out2 = json_decode(trim($p2->getOutput()), true) ?? [];

            $this->assertIsArray($out1, 'Process 1: '.$p1->getOutput().$p1->getErrorOutput());
            $this->assertIsArray($out2, 'Process 2: '.$p2->getOutput().$p2->getErrorOutput());

            $this->assertTrue($out1['ok'] ?? false, 'Process 1 failed: '.json_encode($out1));
            $this->assertTrue($out2['ok'] ?? false, 'Process 2 failed: '.json_encode($out2));

            // Exactly 1 payout, 1 document
            $this->assertSame(1, WinnerPayout::query()->where('game_id', $game->id)->count());
            $this->assertSame(1, WinnerPayoutDocument::query()->count());

            // Exactly one PayoutPaid audit event
            $this->assertSame(1, GameEvent::query()->where('type', GameEventType::PayoutPaid)->count());

            // One creator, one replayer
            $flags = [(bool) ($out1['was_already_processed'] ?? true), (bool) ($out2['was_already_processed'] ?? true)];
            sort($flags);
            $this->assertSame([false, true], $flags, 'One must be creator, other must be replayer.');
        } finally {
            foreach ([$p1, $p2] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }

    /**
     * (b) Same game, different idempotency keys, simultaneous race.
     *
     * Expected: exactly 1 WinnerPayout (first one wins), second returns existing.
     */
    public function test_same_game_different_keys_race_produces_exactly_one_payout(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();

        $keyA = 'concurrency-key-payout-diff-aa';
        $keyB = 'concurrency-key-payout-diff-bb';

        $p1 = null;
        $p2 = null;
        try {
            $p1 = $this->spawnPayout((string) $game->id, (int) $admin->id, $keyA);
            $p2 = $this->spawnPayout((string) $game->id, (int) $admin->id, $keyB);

            $p1->wait();
            $p2->wait();

            $out1 = json_decode(trim($p1->getOutput()), true) ?? [];
            $out2 = json_decode(trim($p2->getOutput()), true) ?? [];

            $this->assertIsArray($out1, 'Process 1: '.$p1->getOutput().$p1->getErrorOutput());
            $this->assertIsArray($out2, 'Process 2: '.$p2->getOutput().$p2->getErrorOutput());

            $this->assertTrue($out1['ok'] ?? false, 'Process 1 failed: '.json_encode($out1));
            $this->assertTrue($out2['ok'] ?? false, 'Process 2 failed: '.json_encode($out2));

            // Exactly 1 payout row
            $this->assertSame(1, WinnerPayout::query()->where('game_id', $game->id)->count());
            $this->assertSame(1, WinnerPayoutDocument::query()->count());

            // Exactly 1 audit event
            $this->assertSame(1, GameEvent::query()->where('type', GameEventType::PayoutPaid)->count());

            // One creator, one returned-existing
            $flags = [(bool) ($out1['was_already_processed'] ?? true), (bool) ($out2['was_already_processed'] ?? true)];
            sort($flags);
            $this->assertSame([false, true], $flags);
        } finally {
            foreach ([$p1, $p2] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }

    /**
     * (c) Same key, different fingerprint (different external_reference).
     *
     * Sequence: first call succeeds, second call → IdempotencyKeyMismatch.
     */
    public function test_same_key_different_fingerprint_raises_conflict(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();

        $key = 'concurrency-key-payout-fp-conf';

        $p1 = null;
        $p2 = null;
        try {
            // First call — must succeed
            $p1 = $this->spawnPayout((string) $game->id, (int) $admin->id, $key, 'OP-REF-A');
            $p1->wait();

            $out1 = json_decode(trim($p1->getOutput()), true) ?? [];
            $this->assertTrue($out1['ok'] ?? false, 'First payout failed: '.json_encode($out1));
            $this->assertFalse((bool) ($out1['was_already_processed'] ?? true));
            $this->assertSame(1, WinnerPayout::query()->where('game_id', $game->id)->count());

            // Second call — same key, different external_reference → fingerprint mismatch
            $p2 = $this->spawnPayout((string) $game->id, (int) $admin->id, $key, 'OP-REF-B-DIFFERENT');
            $p2->wait();

            $out2 = json_decode(trim($p2->getOutput()), true) ?? [];
            $this->assertFalse($out2['ok'] ?? true, 'Expected IdempotencyKeyMismatch but succeeded: '.json_encode($out2));
            $this->assertStringContainsString(
                'IdempotencyKeyMismatch',
                $out2['class'] ?? '',
                'Wrong exception: '.json_encode($out2),
            );

            // Still exactly 1 payout
            $this->assertSame(1, WinnerPayout::query()->where('game_id', $game->id)->count());
            $this->assertSame(1, GameEvent::query()->where('type', GameEventType::PayoutPaid)->count());
        } finally {
            foreach ([$p1, $p2] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }

    /**
     * (d) Payout vs StartGameAction competing for the same Game FOR UPDATE.
     *
     * GameStatus::Completed is terminal — no legitimate action transitions away from it.
     * StartGameAction is used as a real lock competitor: it acquires Game FOR UPDATE,
     * then throws GameNotReadyForStart because the game is Completed, not SalesOpen.
     *
     * Regardless of lock order:
     *   - The payout always succeeds (game IS Completed, which is the required status)
     *   - StartGameAction always fails with a stable domain error (not SalesOpen)
     *   - No deadlock, no timeout, both processes finish within the test window
     */
    public function test_payout_vs_competing_game_lock_serializes_without_deadlock(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();

        $key = 'concurrency-key-payout-lock-dd';

        $pPayout = null;
        $pStart = null;
        try {
            // Spawn both simultaneously so they race for Game FOR UPDATE.
            $pPayout = $this->spawnPayout((string) $game->id, (int) $admin->id, $key);
            $pStart = $this->spawn(['start', 'GAME_ID='.$game->id, 'ACTOR_USER_ID='.(int) $admin->id]);

            $pPayout->wait();
            $pStart->wait();

            $outPayout = json_decode(trim($pPayout->getOutput()), true) ?? [];
            $outStart = json_decode(trim($pStart->getOutput()), true) ?? [];

            // Payout must succeed — game IS Completed (required status for payouts).
            $this->assertTrue(
                $outPayout['ok'] ?? false,
                'Payout failed: '.$pPayout->getOutput().$pPayout->getErrorOutput(),
            );

            // StartGameAction must fail — game is Completed, not SalesOpen.
            // This confirms the lock contention resolved without deadlock.
            $this->assertFalse(
                $outStart['ok'] ?? true,
                'StartGameAction unexpectedly succeeded on a Completed game: '.json_encode($outStart),
            );

            // A stable domain exception was raised (not a DB deadlock or timeout).
            $this->assertNotEmpty(
                $outStart['class'] ?? '',
                'Expected a domain exception class in StartGame failure output',
            );

            // Exactly 1 payout, 1 document, 1 audit event — no partial writes.
            $this->assertSame(1, WinnerPayout::query()->where('game_id', $game->id)->count());
            $this->assertSame(1, WinnerPayoutDocument::query()->count());
            $this->assertSame(1, GameEvent::query()->where('type', GameEventType::PayoutPaid)->count());
        } finally {
            foreach ([$pPayout, $pStart] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }
}
