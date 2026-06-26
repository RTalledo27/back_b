<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Actions\Auth\ResolveSocialIdentityAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Verifies that ResolveSocialIdentityAction serializes concurrent social
 * callbacks using pg_advisory_xact_lock.
 *
 * Two tests use real parallel PHP processes (proc_open + file barrier) to
 * prove that concurrent callbacks for the same identity or same email cannot
 * create duplicate rows.
 */
final class SocialAuthConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    // ─── Advisory lock key ────────────────────────────────────────────────────

    public function test_social_identity_lock_key_is_deterministic_and_stable(): void
    {
        $key1 = ResolveSocialIdentityAction::socialIdentityLockKey('google', 'user-abc');
        $key2 = ResolveSocialIdentityAction::socialIdentityLockKey('google', 'user-abc');
        $key3 = ResolveSocialIdentityAction::socialIdentityLockKey('google', 'user-xyz');
        $key4 = ResolveSocialIdentityAction::socialIdentityLockKey('facebook', 'user-abc');

        $this->assertSame($key1, $key2, 'Same inputs must produce same key');
        $this->assertNotSame($key1, $key3, 'Different provider_user_id must produce different key');
        $this->assertNotSame($key1, $key4, 'Different provider must produce different key');
        $this->assertIsInt($key1);
        $this->assertGreaterThanOrEqual(0, $key1);
        $this->assertLessThanOrEqual(PHP_INT_MAX, $key1);
    }

    // ─── Same identity concurrent callbacks ───────────────────────────────────

    public function test_concurrent_callbacks_same_identity_produce_one_user_and_one_social_account(): void
    {
        $email = 'race-social-'.uniqid().'@example.com';
        $providerId = 'race-provider-'.uniqid();

        $outcomes = $this->runParallelResolutions($email, $providerId, 'google', count: 2);

        foreach ($outcomes as $i => $outcome) {
            $this->assertStringNotContainsString('error:', $outcome,
                "Child process {$i} reported a failure: {$outcome}");
        }

        $this->assertSame(1, User::query()->where('email', $email)->count(),
            'Concurrent callbacks must not create duplicate users');

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame(UserRole::Player, $user->role);
        $this->assertNull($user->password);

        $this->assertSame(1, UserSocialAccount::query()
            ->where('provider', 'google')
            ->where('provider_user_id', $providerId)
            ->count(),
            'Concurrent callbacks must not create duplicate social accounts');
    }

    // ─── Same email, different identities ────────────────────────────────────

    public function test_concurrent_callbacks_same_email_different_identity_prevent_duplicate_user(): void
    {
        $email = 'shared-email-'.uniqid().'@example.com';

        // Two different Google identities that both resolve to the same email.
        $outcomes = $this->runParallelResolutionsForEmails(
            $email,
            providerIds: ['race-id-A-'.uniqid(), 'race-id-B-'.uniqid()],
            provider: 'google',
        );

        // At least one succeeded, none crashed.
        foreach ($outcomes as $i => $outcome) {
            $this->assertStringNotContainsString('error:', $outcome,
                "Child process {$i} reported a failure: {$outcome}");
        }

        $this->assertSame(1, User::query()->where('email', $email)->count(),
            'Two identities sharing the same email must not create two users');

        // One created the user + linked the social account; the other got
        // account_link_required (no linking, no duplicate).
        $linkedAccounts = UserSocialAccount::query()->where('provider_email', $email)->count();
        $this->assertLessThanOrEqual(1, $linkedAccounts,
            'At most one social account should be linked for the shared email');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function runParallelResolutions(string $email, string $providerId, string $provider, int $count): array
    {
        $tempDir = sys_get_temp_dir();
        $id = uniqid('social_conc_', true);

        $barrierFile = $tempDir.DIRECTORY_SEPARATOR.$id.'_barrier.flag';
        $scriptFile = $tempDir.DIRECTORY_SEPARATOR.$id.'_script.php';

        /** @var list<string> $readyFiles */
        $readyFiles = array_map(
            fn (int $i) => $tempDir.DIRECTORY_SEPARATOR.$id."_ready_{$i}.flag",
            range(0, $count - 1),
        );

        try {
            $script = $this->buildResolveScript($email, $providerId, $provider, $barrierFile);
            file_put_contents($scriptFile, $script);

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $processes = [];
            $allPipes = [];

            for ($i = 0; $i < $count; $i++) {
                $cmd = sprintf('"%s" "%s" "%s"', PHP_BINARY, $scriptFile, $readyFiles[$i]);
                $proc = proc_open($cmd, $descriptors, $procPipes, base_path());
                $this->assertIsResource($proc, "Child process {$i} failed to start");
                fclose($procPipes[0]);
                $processes[] = $proc;
                $allPipes[] = $procPipes;
            }

            $deadline = microtime(true) + 15.0;
            while (microtime(true) < $deadline) {
                $allReady = array_reduce(
                    $readyFiles,
                    fn (bool $c, string $rf) => $c && file_exists($rf),
                    true,
                );
                if ($allReady) {
                    break;
                }
                usleep(10_000);
            }

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
     * Two processes, each with a DIFFERENT providerId but the SAME email.
     *
     * @param  list<string>  $providerIds
     * @return list<string>
     */
    private function runParallelResolutionsForEmails(string $email, array $providerIds, string $provider): array
    {
        $tempDir = sys_get_temp_dir();
        $id = uniqid('social_email_conc_', true);

        $barrierFile = $tempDir.DIRECTORY_SEPARATOR.$id.'_barrier.flag';
        $scriptFiles = [];
        $readyFiles = [];

        foreach ($providerIds as $i => $providerId) {
            $scriptFiles[$i] = $tempDir.DIRECTORY_SEPARATOR.$id."_script_{$i}.php";
            $readyFiles[$i] = $tempDir.DIRECTORY_SEPARATOR.$id."_ready_{$i}.flag";
            file_put_contents($scriptFiles[$i], $this->buildResolveScript($email, $providerId, $provider, $barrierFile));
        }

        try {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $processes = [];
            $allPipes = [];

            foreach ($providerIds as $i => $providerId) {
                $cmd = sprintf('"%s" "%s" "%s"', PHP_BINARY, $scriptFiles[$i], $readyFiles[$i]);
                $proc = proc_open($cmd, $descriptors, $procPipes, base_path());
                $this->assertIsResource($proc, "Child process {$i} failed to start");
                fclose($procPipes[0]);
                $processes[$i] = $proc;
                $allPipes[$i] = $procPipes;
            }

            $deadline = microtime(true) + 15.0;
            while (microtime(true) < $deadline) {
                $allReady = array_reduce(
                    $readyFiles,
                    fn (bool $c, string $rf) => $c && file_exists($rf),
                    true,
                );
                if ($allReady) {
                    break;
                }
                usleep(10_000);
            }

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
            foreach ($scriptFiles as $sf) {
                @unlink($sf);
            }
            foreach ($readyFiles as $rf) {
                @unlink($rf);
            }
        }
    }

    private function buildResolveScript(
        string $email,
        string $providerId,
        string $provider,
        string $barrierFile,
    ): string {
        $init = '<?php'.PHP_EOL
            .'$email       = '.var_export($email, true).';'.PHP_EOL
            .'$providerId  = '.var_export($providerId, true).';'.PHP_EOL
            .'$provider    = '.var_export($provider, true).';'.PHP_EOL
            .'$barrierFile = '.var_export($barrierFile, true).';'.PHP_EOL
            .'$baseDir     = '.var_export(base_path(), true).';'.PHP_EOL;

        $body = <<<'SCRIPT'

$readyFile = $argv[1] ?? null;

require $baseDir . '/vendor/autoload.php';
$app = require $baseDir . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($readyFile !== null) {
    touch($readyFile);
}

// Busy-wait at the barrier (up to 15 s).
for ($i = 0; $i < 1500; $i++) {
    if (file_exists($barrierFile)) {
        break;
    }
    usleep(10000);
}

try {
    $action   = app(\App\Actions\Auth\ResolveSocialIdentityAction::class);
    $data     = new \App\DTOs\Auth\SocialUserData($provider, $providerId, $email, true, 'Concurrent User');
    $result   = $action->execute($data);
    echo $result->outcome->value;
} catch (\Throwable $e) {
    echo 'error:' . $e->getMessage();
}
SCRIPT;

        return $init.$body;
    }
}
