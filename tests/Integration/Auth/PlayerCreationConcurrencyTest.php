<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Actions\Auth\CreatePlayerInvitationAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Verifies that CreatePlayerInvitationAction serializes concurrent invitations
 * for the same email using pg_advisory_xact_lock.
 *
 * Two tests:
 *   1. Advisory lock mechanism — proves via a second PDO connection that the lock
 *      actually blocks concurrent transactions holding the same key.
 *   2. Concurrent processes — two real PHP child processes race to invite the same
 *      email; asserts exactly one user and one active invitation result.
 */
final class PlayerCreationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    // ─── Advisory lock mechanism ──────────────────────────────────────────────

    public function test_email_advisory_lock_key_is_deterministic_and_stable(): void
    {
        $key1 = CreatePlayerInvitationAction::emailAdvisoryLockKey('alice@example.com');
        $key2 = CreatePlayerInvitationAction::emailAdvisoryLockKey('alice@example.com');
        $key3 = CreatePlayerInvitationAction::emailAdvisoryLockKey('bob@example.com');

        $this->assertSame($key1, $key2, 'Same email must produce same key');
        $this->assertNotSame($key1, $key3, 'Different emails must produce different keys');
        $this->assertIsInt($key1);
        $this->assertGreaterThanOrEqual(0, $key1);
        // Must fit in PostgreSQL bigint (max 2^63 − 1)
        $this->assertLessThanOrEqual(PHP_INT_MAX, $key1);
    }

    public function test_advisory_lock_blocks_concurrent_transaction_for_same_email(): void
    {
        $email = 'advisory-lock@example.com';
        $lockKey = CreatePlayerInvitationAction::emailAdvisoryLockKey($email);

        // Second connection acquires the advisory lock inside a transaction.
        $pdo2 = $this->openSecondConnection();
        $pdo2->beginTransaction();
        $pdo2->exec("SELECT pg_advisory_xact_lock({$lockKey})");

        // Main connection tries to acquire the same key (non-blocking) — must fail.
        $acquired = DB::transaction(function () use ($lockKey): bool {
            $row = DB::selectOne('SELECT pg_try_advisory_xact_lock(?) AS acquired', [$lockKey]);

            return (bool) $row->acquired;
        });

        $this->assertFalse($acquired,
            'pg_advisory_xact_lock must block concurrent transaction holding the same key');

        // Release second connection's lock; now the key is available again.
        $pdo2->rollBack();

        $acquiredAfterRelease = DB::transaction(function () use ($lockKey): bool {
            $row = DB::selectOne('SELECT pg_try_advisory_xact_lock(?) AS acquired', [$lockKey]);

            return (bool) $row->acquired;
        });

        $this->assertTrue($acquiredAfterRelease,
            'Advisory lock must be acquirable after the holding transaction ends');
    }

    // ─── Concurrent process race ──────────────────────────────────────────────

    public function test_concurrent_invitations_produce_exactly_one_user_and_one_active_invitation(): void
    {
        $admin = User::factory()->admin()->create();
        $email = 'race-'.uniqid().'@example.com';

        $outcomes = $this->runParallelInvitations($email, $admin->id, count: 2);

        // Neither process must report a transaction abort or PHP error.
        foreach ($outcomes as $i => $outcome) {
            $this->assertStringNotContainsString('error:', $outcome,
                "Child process {$i} reported a failure: {$outcome}");
        }

        // Exactly one user must exist.
        $this->assertSame(1,
            User::query()->where('email', $email)->count(),
            'Concurrent invitations must not create duplicate users');

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame(UserRole::Player, $user->role,
            'Player role must not be elevated by concurrent creation');
        $this->assertNull($user->password,
            'User must remain passwordless (pending activation)');

        // Exactly one active invitation must exist.
        $activeInvitations = UserInvitation::query()
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->count();

        $this->assertSame(1, $activeInvitations,
            'Exactly one active invitation must exist after the race');

        // Any invitation displaced by the winner must be revoked, not left dangling.
        $totalInvitations = UserInvitation::query()->where('user_id', $user->id)->count();

        if ($totalInvitations > 1) {
            $revokedCount = UserInvitation::query()
                ->where('user_id', $user->id)
                ->whereNotNull('revoked_at')
                ->whereNull('consumed_at')
                ->count();

            $this->assertSame(
                $totalInvitations - 1,
                $revokedCount,
                'Every displaced invitation must be revoked (not consumed, not dangling)'
            );
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Launch $count child PHP processes that race to invite $email.
     * Each process waits at a file barrier so all of them start simultaneously.
     *
     * @return list<string> stdout of each process ('invited', 'reinvited', or 'error:…')
     */
    private function runParallelInvitations(string $email, int $adminId, int $count): array
    {
        $tempDir = sys_get_temp_dir();
        $id = uniqid('conc_', true);

        $barrierFile = $tempDir.DIRECTORY_SEPARATOR.$id.'_barrier.flag';
        $scriptFile = $tempDir.DIRECTORY_SEPARATOR.$id.'_script.php';

        /** @var list<string> $readyFiles */
        $readyFiles = array_map(
            fn (int $i) => $tempDir.DIRECTORY_SEPARATOR.$id."_ready_{$i}.flag",
            range(0, $count - 1)
        );

        try {
            file_put_contents($scriptFile, $this->buildInviteScript($adminId, $email, $barrierFile));

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $processes = [];
            $allPipes = [];

            for ($i = 0; $i < $count; $i++) {
                // Pass the ready-file path as $argv[1] so the child signals when loaded.
                $cmd = sprintf('"%s" "%s" "%s"', PHP_BINARY, $scriptFile, $readyFiles[$i]);
                $proc = proc_open($cmd, $descriptors, $procPipes, base_path());

                $this->assertIsResource($proc,
                    "Child process {$i} must start successfully (cmd: {$cmd})");

                fclose($procPipes[0]); // not needed
                $processes[] = $proc;
                $allPipes[] = $procPipes;
            }

            // Wait up to 15 s for every child to signal it is loaded and waiting.
            $deadline = microtime(true) + 15.0;
            while (microtime(true) < $deadline) {
                $allReady = array_reduce(
                    $readyFiles,
                    fn (bool $carry, string $rf) => $carry && file_exists($rf),
                    true
                );
                if ($allReady) {
                    break;
                }
                usleep(10_000); // 10 ms poll
            }

            // Open the barrier — all waiting processes proceed simultaneously.
            file_put_contents($barrierFile, '1');

            $outcomes = [];
            foreach ($processes as $i => $proc) {
                $stdout = stream_get_contents($allPipes[$i][1]);
                $stderr = stream_get_contents($allPipes[$i][2]);
                fclose($allPipes[$i][1]);
                fclose($allPipes[$i][2]);
                proc_close($proc);

                $outcomes[] = trim($stdout !== '' ? $stdout : 'error:'.$stderr);
            }

            return $outcomes;
        } finally {
            @unlink($barrierFile);
            @unlink($scriptFile);
            foreach ($readyFiles as $rf) {
                @unlink($rf);
            }
        }
    }

    /**
     * Builds a self-contained PHP script for the child processes.
     *
     * Dynamic values (adminId, email, paths) are embedded using var_export so
     * backslashes and special characters are correctly escaped on all platforms.
     * The script body uses NOWDOC (no interpolation) to avoid escaping issues.
     */
    private function buildInviteScript(int $adminId, string $email, string $barrierFile): string
    {
        $init = '<?php'.PHP_EOL
            .'$adminId     = '.$adminId.';'.PHP_EOL
            .'$email       = '.var_export($email, true).';'.PHP_EOL
            .'$barrierFile = '.var_export($barrierFile, true).';'.PHP_EOL
            .'$baseDir     = '.var_export(base_path(), true).';'.PHP_EOL;

        $body = <<<'SCRIPT'

// $argv[1] = ready file — signal to the parent that this process is loaded.
$readyFile = $argv[1] ?? null;

require $baseDir . '/vendor/autoload.php';
$app = require $baseDir . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($readyFile !== null) {
    touch($readyFile);
}

// Busy-wait at the barrier (up to 15 seconds at 10 ms intervals).
for ($i = 0; $i < 1500; $i++) {
    if (file_exists($barrierFile)) {
        break;
    }
    usleep(10000);
}

try {
    $action = app(\App\Actions\Auth\CreatePlayerInvitationAction::class);
    $data   = new \App\DTOs\Auth\CreatePlayerData('Concurrent', $email, $adminId);
    $result = $action->execute($data);
    echo $result->outcome->value;
} catch (\Throwable $e) {
    echo 'error:' . $e->getMessage();
}
SCRIPT;

        return $init.$body;
    }

    private function openSecondConnection(): PDO
    {
        /** @var array{host:string,port:int|string,database:string,username:string,password:string} $cfg */
        $cfg = config('database.connections.pgsql');

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $cfg['host'],
            (int) $cfg['port'],
            $cfg['database'],
        );

        return new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
