<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotencyClaim;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotencyClaimResult;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotencyKeyStore;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression test: an abandoned idempotency row (completed_at = NULL, lock
 * older than the in-progress timeout) is bound to its ORIGINAL payload.
 * A different payload reusing the same key must be rejected with
 * PayloadMismatch — never silently hijack the abandoned slot.
 */
final class IdempotencyAbandonedPayloadMismatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_abandoned_row_with_different_payload_returns_payload_mismatch_then_owner_reclaims(): void
    {
        $user = User::factory()->create();
        $contextOwner = IdempotencyContext::make(
            userId: $user->id,
            method: 'POST',
            path: 'api/v1/games/x/reservations',
            key: 'shared-key-abcdefgh1234567890',
            payloadComponents: ['payload' => 'A'],
        );
        $contextIntruder = IdempotencyContext::make(
            userId: $user->id,
            method: 'POST',
            path: 'api/v1/games/x/reservations',
            key: 'shared-key-abcdefgh1234567890',
            payloadComponents: ['payload' => 'B'],
        );

        $rowId = (string) Str::uuid7();
        $originalLockedAt = now()->subMinutes(5); // safely past the 60-second in-progress timeout

        DB::table('idempotency_keys')->insert([
            'id' => $rowId,
            'user_id' => $user->id,
            'request_method' => $contextOwner->method,
            'request_path' => $contextOwner->path,
            'key' => $contextOwner->key,
            'payload_sha256' => $contextOwner->payloadSha256,
            'locked_at' => $originalLockedAt,
            'completed_at' => null,
            'expires_at' => now()->addDay(),
        ]);

        // 1. Intruder with payload B claims → must be PayloadMismatch.
        $intruderClaim = $this->invokeTryClaim($contextIntruder);
        $this->assertSame(IdempotencyClaimResult::PayloadMismatch, $intruderClaim->result);

        // 2. Row must be untouched: same payload hash, same locked_at, no
        //    completed_at, no result_payload.
        $row = DB::table('idempotency_keys')->where('id', $rowId)->first();
        $this->assertNotNull($row);
        $this->assertSame(
            $contextOwner->payloadSha256,
            $row->payload_sha256,
            'A mismatched reclaim attempt must not overwrite payload_sha256.',
        );
        $this->assertEqualsWithDelta(
            $originalLockedAt->timestamp,
            Carbon::parse((string) $row->locked_at)->timestamp,
            1.0,
            'A mismatched reclaim attempt must not refresh locked_at.',
        );
        $this->assertNull($row->completed_at);
        $this->assertNull($row->result_payload);

        // 3. Owner with the ORIGINAL payload A may still reclaim the slot.
        $ownerClaim = $this->invokeTryClaim($contextOwner);
        $this->assertSame(IdempotencyClaimResult::Claimed, $ownerClaim->result);
        $this->assertSame($rowId, $ownerClaim->rowId);

        // 4. After the successful reclaim, locked_at is refreshed but
        //    payload_sha256 is still the original (the row is the same one).
        $refreshed = DB::table('idempotency_keys')->where('id', $rowId)->first();
        $this->assertNotNull($refreshed);
        $this->assertSame($contextOwner->payloadSha256, $refreshed->payload_sha256);
        $this->assertGreaterThan(
            $originalLockedAt->timestamp,
            Carbon::parse((string) $refreshed->locked_at)->timestamp,
        );
    }

    private function invokeTryClaim(IdempotencyContext $context): IdempotencyClaim
    {
        return $this->app->make(IdempotencyKeyStore::class)->tryClaim($context);
    }
}
