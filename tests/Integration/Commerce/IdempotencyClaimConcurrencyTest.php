<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotencyClaim;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotencyClaimResult;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotencyKeyStore;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\Integration\Support\RawPdoConnection;
use Tests\TestCase;

/**
 * Cross-connection concurrency requires committed data visible from a
 * second PDO. DatabaseTruncation runs tests without a TX wrap (truncating
 * between tests) so the row inserted from Laravel's connection is visible
 * to the raw PDO connection used for the race.
 */
final class IdempotencyClaimConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // Raw PDO writes bypass Laravel's dirty-table tracker, so the
        // automatic DatabaseTruncation pass misses them. Wipe the tables we
        // know are touched (directly or via FKs) before the trait runs.
        DB::statement(
            'TRUNCATE TABLE idempotency_keys, users RESTART IDENTITY CASCADE'
        );

        parent::tearDown();
    }

    public function test_two_simultaneous_inserts_serialize_via_unique_index(): void
    {
        $user = User::factory()->create();
        $context = IdempotencyContext::make(
            userId: $user->id,
            method: 'POST',
            path: 'api/v1/games/x/reservations',
            key: 'race-key-abcdefgh1234567890',
            payloadComponents: ['game_id' => 'x', 'game_number_ids' => ['a', 'b']],
        );

        $pdoFirst = RawPdoConnection::open();
        $pdoSecond = RawPdoConnection::open();

        try {
            $pdoFirst->beginTransaction();
            $stmt = $pdoFirst->prepare(
                'INSERT INTO idempotency_keys (id, user_id, request_method, request_path, key, payload_sha256, locked_at, expires_at) '
                ."VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW() + interval '1 hour') "
                .'ON CONFLICT (user_id, request_method, request_path, key) DO NOTHING'
            );
            $stmt->execute([
                (string) Str::uuid7(),
                $context->userId,
                $context->method,
                $context->path,
                $context->key,
                $context->payloadSha256,
            ]);

            $this->assertSame(1, $stmt->rowCount(), 'First connection must succeed inserting the claim.');

            // Second connection attempts the same ON CONFLICT DO NOTHING on the
            // same unique key while the first transaction is still open. Postgres
            // blocks the second statement until the first resolves.
            $pdoSecond->exec("SET statement_timeout = '500ms'");
            $stmt2 = $pdoSecond->prepare(
                'INSERT INTO idempotency_keys (id, user_id, request_method, request_path, key, payload_sha256, locked_at, expires_at) '
                ."VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW() + interval '1 hour') "
                .'ON CONFLICT (user_id, request_method, request_path, key) DO NOTHING'
            );

            $blockedThenTimedOut = false;
            try {
                $stmt2->execute([
                    (string) Str::uuid7(),
                    $context->userId,
                    $context->method,
                    $context->path,
                    $context->key,
                    $context->payloadSha256,
                ]);
            } catch (PDOException $e) {
                // 57014 = query_canceled by statement_timeout — only triggers
                // because we were blocked waiting for the first transaction.
                $blockedThenTimedOut = str_contains($e->getMessage(), 'canceling statement')
                    || str_contains($e->getMessage(), '57014');
            }

            $this->assertTrue(
                $blockedThenTimedOut,
                'Second connection must block on the uncommitted INSERT and time out.',
            );

            $pdoFirst->commit();
        } finally {
            RawPdoConnection::teardown($pdoFirst);
            RawPdoConnection::teardown($pdoSecond);
        }
    }

    public function test_try_claim_returns_in_progress_for_fresh_locked_row(): void
    {
        $user = User::factory()->create();
        $context = IdempotencyContext::make(
            userId: $user->id,
            method: 'POST',
            path: 'api/v1/games/x/reservations',
            key: 'progress-key-abcdefgh1234567890',
            payloadComponents: ['game_id' => 'x', 'game_number_ids' => ['a']],
        );

        DB::table('idempotency_keys')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'request_method' => $context->method,
            'request_path' => $context->path,
            'key' => $context->key,
            'payload_sha256' => $context->payloadSha256,
            'locked_at' => now(),
            'completed_at' => null,
            'expires_at' => now()->addDay(),
        ]);

        $claim = $this->invokeTryClaim($context);

        $this->assertSame(IdempotencyClaimResult::InProgress, $claim->result);
    }

    public function test_try_claim_reclaims_an_abandoned_row(): void
    {
        $user = User::factory()->create();
        $context = IdempotencyContext::make(
            userId: $user->id,
            method: 'POST',
            path: 'api/v1/games/x/reservations',
            key: 'reclaim-key-abcdefgh12345678',
            payloadComponents: ['game_id' => 'x', 'game_number_ids' => ['a']],
        );

        DB::table('idempotency_keys')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'request_method' => $context->method,
            'request_path' => $context->path,
            'key' => $context->key,
            'payload_sha256' => $context->payloadSha256,
            // Locked far enough in the past that the in-progress timeout has elapsed.
            'locked_at' => now()->subMinutes(5),
            'completed_at' => null,
            'expires_at' => now()->addDay(),
        ]);

        $claim = $this->invokeTryClaim($context);

        $this->assertSame(IdempotencyClaimResult::Claimed, $claim->result);
        $this->assertNotNull($claim->rowId);
    }

    public function test_try_claim_returns_completed_same_payload_for_replay(): void
    {
        $user = User::factory()->create();
        $context = IdempotencyContext::make(
            userId: $user->id,
            method: 'POST',
            path: 'api/v1/games/x/reservations',
            key: 'replay-key-abcdefgh1234567890',
            payloadComponents: ['game_id' => 'x', 'game_number_ids' => ['a']],
        );

        DB::table('idempotency_keys')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'request_method' => $context->method,
            'request_path' => $context->path,
            'key' => $context->key,
            'payload_sha256' => $context->payloadSha256,
            'result_payload' => json_encode(['answer' => 42]),
            'locked_at' => now()->subMinute(),
            'completed_at' => now()->subSeconds(30),
            'expires_at' => now()->addDay(),
        ]);

        $claim = $this->invokeTryClaim($context);

        $this->assertSame(IdempotencyClaimResult::CompletedSamePayload, $claim->result);
        $this->assertSame(['answer' => 42], $claim->resultPayload);
    }

    public function test_try_claim_returns_payload_mismatch_for_completed_with_different_payload(): void
    {
        $user = User::factory()->create();
        $contextOriginal = IdempotencyContext::make(
            userId: $user->id,
            method: 'POST',
            path: 'api/v1/games/x/reservations',
            key: 'mismatch-key-abcdefgh1234567890',
            payloadComponents: ['game_id' => 'x', 'game_number_ids' => ['a']],
        );
        $contextDifferent = IdempotencyContext::make(
            userId: $user->id,
            method: 'POST',
            path: 'api/v1/games/x/reservations',
            key: 'mismatch-key-abcdefgh1234567890',
            payloadComponents: ['game_id' => 'x', 'game_number_ids' => ['b']],
        );

        DB::table('idempotency_keys')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'request_method' => $contextOriginal->method,
            'request_path' => $contextOriginal->path,
            'key' => $contextOriginal->key,
            'payload_sha256' => $contextOriginal->payloadSha256,
            'result_payload' => json_encode(['answer' => 42]),
            'locked_at' => now()->subMinute(),
            'completed_at' => now()->subSeconds(30),
            'expires_at' => now()->addDay(),
        ]);

        $claim = $this->invokeTryClaim($contextDifferent);

        $this->assertSame(IdempotencyClaimResult::PayloadMismatch, $claim->result);
    }

    private function invokeTryClaim(IdempotencyContext $context): IdempotencyClaim
    {
        return $this->app->make(IdempotencyKeyStore::class)->tryClaim($context);
    }
}
