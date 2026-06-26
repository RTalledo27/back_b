<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Actions\Auth\HandleSocialLinkCallbackAction;
use App\Models\OauthAttempt;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies that HandleSocialLinkCallbackAction and UnlinkSocialAccountAction
 * serialise concurrent operations using pg_advisory_xact_lock.
 *
 * All tests use real parallel PHP processes (proc_open + file barrier).
 */
final class SocialLinkConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    // ─── Advisory lock key determinism ────────────────────────────────────────

    public function test_user_provider_link_key_is_deterministic_and_stable(): void
    {
        $key1 = HandleSocialLinkCallbackAction::userProviderLinkKey(42, 'google');
        $key2 = HandleSocialLinkCallbackAction::userProviderLinkKey(42, 'google');
        $key3 = HandleSocialLinkCallbackAction::userProviderLinkKey(42, 'facebook');
        $key4 = HandleSocialLinkCallbackAction::userProviderLinkKey(99, 'google');

        $this->assertSame($key1, $key2, 'Same inputs must produce the same key');
        $this->assertNotSame($key1, $key3, 'Different provider must produce a different key');
        $this->assertNotSame($key1, $key4, 'Different user_id must produce a different key');
        $this->assertIsInt($key1);
        $this->assertGreaterThanOrEqual(0, $key1);
        $this->assertLessThanOrEqual(PHP_INT_MAX, $key1);
    }

    // ─── Two users race to link the same identity ─────────────────────────────

    /**
     * Two users concurrently try to link the same (provider, provider_user_id).
     * Only one should succeed; the other must get social_identity_conflict.
     * Exactly one UserSocialAccount row must exist for that identity afterwards.
     */
    public function test_two_users_link_same_identity_only_one_succeeds(): void
    {
        $providerId = 'race-identity-'.uniqid();

        // Create two users and two link attempts for the shared identity.
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $plainStateA = Str::random(64);
        $plainStateB = Str::random(64);

        OauthAttempt::create([
            'provider' => 'google',
            'purpose' => 'link',
            'initiated_by_user_id' => $userA->id,
            'state_hash' => hash('sha256', $plainStateA),
            'expires_at' => now()->addMinutes(10),
        ]);

        OauthAttempt::create([
            'provider' => 'google',
            'purpose' => 'link',
            'initiated_by_user_id' => $userB->id,
            'state_hash' => hash('sha256', $plainStateB),
            'expires_at' => now()->addMinutes(10),
        ]);

        $outcomes = $this->runParallelLinkScripts([
            ['plainState' => $plainStateA, 'providerId' => $providerId, 'email' => 'race-a@example.com'],
            ['plainState' => $plainStateB, 'providerId' => $providerId, 'email' => 'race-a@example.com'],
        ]);

        $values = array_map('trim', $outcomes);

        // One must have linked, the other must have gotten a conflict.
        $this->assertEqualsCanonicalizing(
            ['social_linked', 'social_identity_conflict'],
            $values,
            'Exactly one link and one conflict expected; got: '.implode(', ', $values),
        );

        // DB: exactly one social account for that identity.
        $this->assertSame(1, UserSocialAccount::query()
            ->where('provider', 'google')
            ->where('provider_user_id', $providerId)
            ->count(),
            'Concurrent links must not create duplicate user_social_accounts');
    }

    // ─── Same user races two link attempts for the same provider ─────────────

    /**
     * A single user concurrently submits two link callbacks for the same provider
     * but different provider_user_ids. The advisory lock on (user_id, provider)
     * ensures only one succeeds; the other gets provider_already_linked.
     */
    public function test_same_user_two_link_attempts_same_provider_only_one_survives(): void
    {
        $user = User::factory()->create();

        $plainStateC1 = Str::random(64);
        $plainStateC2 = Str::random(64);

        OauthAttempt::create([
            'provider' => 'google',
            'purpose' => 'link',
            'initiated_by_user_id' => $user->id,
            'state_hash' => hash('sha256', $plainStateC1),
            'expires_at' => now()->addMinutes(10),
        ]);

        OauthAttempt::create([
            'provider' => 'google',
            'purpose' => 'link',
            'initiated_by_user_id' => $user->id,
            'state_hash' => hash('sha256', $plainStateC2),
            'expires_at' => now()->addMinutes(10),
        ]);

        $outcomes = $this->runParallelLinkScripts([
            ['plainState' => $plainStateC1, 'providerId' => 'g-race-C1-'.uniqid(), 'email' => 'race-c@example.com'],
            ['plainState' => $plainStateC2, 'providerId' => 'g-race-C2-'.uniqid(), 'email' => 'race-c2@example.com'],
        ]);

        $values = array_map('trim', $outcomes);

        $this->assertEqualsCanonicalizing(
            ['social_linked', 'provider_already_linked'],
            $values,
            'One link and one provider_already_linked expected; got: '.implode(', ', $values),
        );

        // Exactly one google account for the user.
        $this->assertSame(1, UserSocialAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', 'google')
            ->count(),
            'Concurrent link + provider-already-linked must not create duplicates');
    }

    // ─── Link + unlink race ───────────────────────────────────────────────────

    /**
     * Concurrent link (new identity) and unlink (existing identity) for the
     * same user and provider must always leave the DB in a consistent state.
     *
     * Verified invariants per iteration:
     *   - Both processes terminate (no deadlock, no timeout).
     *   - At most one google account per user.
     *   - User retains at least one authentication method (has a password).
     *   - No partial state: provider_user_id columns are never NULL.
     *   - The OauthAttempt is consumed (prevents replay after a race).
     *
     * The test runs 3 iterations with distinct data to increase the probability
     * that the race is exercised in different serialization orders.
     */
    public function test_link_and_unlink_same_provider_concurrently_leaves_consistent_state(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->runSingleLinkUnlinkRace($i);
        }
    }

    private function runSingleLinkUnlinkRace(int $iteration): void
    {
        $password = 'pw-race-iter-'.$iteration;
        $user = User::factory()->create(['password' => $password]);

        // Pre-link an existing google account so the unlink has something to remove.
        UserSocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'g-existing-iter-'.$iteration,
            'provider_email' => 'existing'.$iteration.'@google.com',
            'provider_email_verified_at' => now(),
        ]);

        // Prepare a link attempt for a NEW google identity (different provider_user_id).
        $plainState = Str::random(64);
        $attempt = OauthAttempt::create([
            'provider' => 'google',
            'purpose' => 'link',
            'initiated_by_user_id' => $user->id,
            'state_hash' => hash('sha256', $plainState),
            'expires_at' => now()->addMinutes(10),
        ]);

        $tempDir = sys_get_temp_dir();
        $id = uniqid('link_unlink_'.$iteration.'_', true);

        $barrierFile = $tempDir.DIRECTORY_SEPARATOR.$id.'_barrier.flag';
        $readyA = $tempDir.DIRECTORY_SEPARATOR.$id.'_ready_A.flag';
        $readyB = $tempDir.DIRECTORY_SEPARATOR.$id.'_ready_B.flag';
        $scriptA = $tempDir.DIRECTORY_SEPARATOR.$id.'_scriptA.php';
        $scriptB = $tempDir.DIRECTORY_SEPARATOR.$id.'_scriptB.php';

        try {
            $newProviderId = 'g-new-iter-'.$iteration.'-'.uniqid();

            file_put_contents($scriptA, $this->buildLinkScript(
                $plainState, $newProviderId, 'race'.$iteration.'@example.com', 'google', $barrierFile,
            ));

            file_put_contents($scriptB, $this->buildUnlinkScript(
                $user->id, 'google', $password, $barrierFile,
            ));

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            $procA = proc_open(sprintf('"%s" "%s" "%s"', PHP_BINARY, $scriptA, $readyA), $descriptors, $pipesA, base_path());
            $procB = proc_open(sprintf('"%s" "%s" "%s"', PHP_BINARY, $scriptB, $readyB), $descriptors, $pipesB, base_path());

            $this->assertIsResource($procA, "Iter {$iteration}: link process failed to start");
            $this->assertIsResource($procB, "Iter {$iteration}: unlink process failed to start");
            fclose($pipesA[0]);
            fclose($pipesB[0]);

            $deadline = microtime(true) + 15.0;
            while (microtime(true) < $deadline) {
                if (file_exists($readyA) && file_exists($readyB)) {
                    break;
                }
                usleep(10_000);
            }

            // Both processes are ready — release barrier to start the race.
            file_put_contents($barrierFile, '1');

            $outA = trim((string) stream_get_contents($pipesA[1]));
            $errA = trim((string) stream_get_contents($pipesA[2]));
            $outB = trim((string) stream_get_contents($pipesB[1]));
            $errB = trim((string) stream_get_contents($pipesB[2]));

            fclose($pipesA[1]);
            fclose($pipesA[2]);
            fclose($pipesB[1]);
            fclose($pipesB[2]);
            proc_close($procA);
            proc_close($procB);

            $resultA = $outA !== '' ? $outA : 'error:'.$errA;
            $resultB = $outB !== '' ? $outB : 'error:'.$errB;

            // ① Both processes must terminate cleanly (no uncaught exceptions).
            $this->assertStringNotContainsString('error:', $resultA, "Iter {$iteration} link failed: {$resultA}");
            $this->assertStringNotContainsString('error:', $resultB, "Iter {$iteration} unlink failed: {$resultB}");

            // ② At most one google account for the user.
            $googleCount = UserSocialAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', 'google')
                ->count();
            $this->assertLessThanOrEqual(1, $googleCount,
                "Iter {$iteration}: found {$googleCount} google accounts — must be ≤ 1");

            // ③ User still exists and retains at least one auth method (the password).
            $this->assertModelExists($user);
            $this->assertNotNull($user->fresh()->password,
                "Iter {$iteration}: user's password must survive the race");

            // ④ No partial social account rows (provider_user_id must never be NULL).
            $partialRows = UserSocialAccount::query()
                ->where('user_id', $user->id)
                ->whereNull('provider_user_id')
                ->count();
            $this->assertSame(0, $partialRows, "Iter {$iteration}: found partial social account rows");

            // ⑤ The OauthAttempt must have been consumed — no replay is possible.
            $this->assertNotNull($attempt->fresh()->consumed_at,
                "Iter {$iteration}: OauthAttempt must be consumed after the race");
        } finally {
            @unlink($barrierFile);
            @unlink($readyA);
            @unlink($readyB);
            @unlink($scriptA);
            @unlink($scriptB);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param  list<array{plainState: string, providerId: string, email: string}>  $workers
     * @return list<string>
     */
    private function runParallelLinkScripts(array $workers): array
    {
        $tempDir = sys_get_temp_dir();
        $id = uniqid('link_conc_', true);

        $barrierFile = $tempDir.DIRECTORY_SEPARATOR.$id.'_barrier.flag';
        $scriptFiles = [];
        $readyFiles = [];

        foreach ($workers as $i => $w) {
            $scriptFiles[$i] = $tempDir.DIRECTORY_SEPARATOR.$id."_script_{$i}.php";
            $readyFiles[$i] = $tempDir.DIRECTORY_SEPARATOR.$id."_ready_{$i}.flag";
            file_put_contents(
                $scriptFiles[$i],
                $this->buildLinkScript($w['plainState'], $w['providerId'], $w['email'], 'google', $barrierFile),
            );
        }

        try {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $processes = [];
            $allPipes = [];

            foreach ($workers as $i => $w) {
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
                $stdout = (string) stream_get_contents($allPipes[$i][1]);
                $stderr = (string) stream_get_contents($allPipes[$i][2]);
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

    private function buildLinkScript(
        string $plainState,
        string $providerId,
        string $email,
        string $provider,
        string $barrierFile,
    ): string {
        $init = '<?php'.PHP_EOL
            .'$plainState  = '.var_export($plainState, true).';'.PHP_EOL
            .'$providerId  = '.var_export($providerId, true).';'.PHP_EOL
            .'$email       = '.var_export($email, true).';'.PHP_EOL
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

for ($i = 0; $i < 1500; $i++) {
    if (file_exists($barrierFile)) { break; }
    usleep(10000);
}

try {
    $stateHash  = hash('sha256', $plainState);
    $socialUser = new \App\DTOs\Auth\SocialUserData($provider, $providerId, $email, true, 'Race User');
    $action     = app(\App\Actions\Auth\HandleSocialLinkCallbackAction::class);
    $result     = $action->execute($stateHash, $socialUser);
    echo $result->outcome->value;
} catch (\Throwable $e) {
    echo 'error:' . $e->getMessage();
}
SCRIPT;

        return $init.$body;
    }

    private function buildUnlinkScript(
        int $userId,
        string $provider,
        string $currentPassword,
        string $barrierFile,
    ): string {
        $init = '<?php'.PHP_EOL
            .'$userId          = '.var_export($userId, true).';'.PHP_EOL
            .'$provider        = '.var_export($provider, true).';'.PHP_EOL
            .'$currentPassword = '.var_export($currentPassword, true).';'.PHP_EOL
            .'$barrierFile     = '.var_export($barrierFile, true).';'.PHP_EOL
            .'$baseDir         = '.var_export(base_path(), true).';'.PHP_EOL;

        $body = <<<'SCRIPT'

$readyFile = $argv[1] ?? null;

require $baseDir . '/vendor/autoload.php';
$app = require $baseDir . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($readyFile !== null) {
    touch($readyFile);
}

for ($i = 0; $i < 1500; $i++) {
    if (file_exists($barrierFile)) { break; }
    usleep(10000);
}

try {
    $user   = \App\Models\User::findOrFail($userId);
    $action = app(\App\Actions\Auth\UnlinkSocialAccountAction::class);
    $action->execute($user, $provider, $currentPassword);
    echo 'unlinked';
} catch (\App\Exceptions\Auth\SocialAuthException $e) {
    // Stable expected outcomes from the action (e.g. not_linked if the link
    // operation won the race and provider_already_linked triggered a cascade).
    echo $e->errorCode;
} catch (\Throwable $e) {
    echo 'error:' . $e->getMessage();
}
SCRIPT;

        return $init.$body;
    }
}
