<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The idempotency_keys table is infrastructure, so we exercise it directly
 * through DB:: rather than via a domain model (the IdempotencyKey model
 * lives in Commerce\Infrastructure and is built in Block 2.2/2.4).
 */
final class IdempotencyKeyConstraintsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function insertKey(array $attrs): void
    {
        DB::table('idempotency_keys')->insert(array_replace([
            'id' => (string) Str::uuid7(),
            'request_method' => 'POST',
            'request_path' => '/api/v1/games/x/reservations',
            'key' => 'k-'.Str::random(8),
            'payload_sha256' => str_repeat('a', 64),
            'result_payload' => null,
            'locked_at' => now(),
            'completed_at' => null,
            'expires_at' => now()->addDay(),
        ], $attrs));
    }

    public function test_user_id_is_not_null(): void
    {
        $this->expectException(QueryException::class);

        $this->insertKey(['user_id' => null]);
    }

    public function test_composite_unique_blocks_same_user_method_path_key(): void
    {
        $user = User::factory()->create();

        $this->insertKey([
            'user_id' => $user->id,
            'request_method' => 'POST',
            'request_path' => '/api/v1/admin/payments/123/approve',
            'key' => 'fixed-key',
        ]);

        $this->expectException(QueryException::class);

        $this->insertKey([
            'user_id' => $user->id,
            'request_method' => 'POST',
            'request_path' => '/api/v1/admin/payments/123/approve',
            'key' => 'fixed-key',
        ]);
    }

    public function test_different_users_may_reuse_the_same_key(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->insertKey([
            'user_id' => $a->id,
            'request_method' => 'POST',
            'request_path' => '/api/v1/me/orders/x/payment-evidence',
            'key' => 'shared-key',
        ]);

        $this->insertKey([
            'user_id' => $b->id,
            'request_method' => 'POST',
            'request_path' => '/api/v1/me/orders/x/payment-evidence',
            'key' => 'shared-key',
        ]);

        $this->assertSame(2, DB::table('idempotency_keys')->count());
    }

    public function test_same_user_different_endpoint_may_reuse_key(): void
    {
        $user = User::factory()->create();

        $this->insertKey([
            'user_id' => $user->id,
            'request_method' => 'POST',
            'request_path' => '/api/v1/games/A/reservations',
            'key' => 'k-1',
        ]);

        $this->insertKey([
            'user_id' => $user->id,
            'request_method' => 'POST',
            'request_path' => '/api/v1/games/B/reservations',
            'key' => 'k-1',
        ]);

        $this->assertSame(2, DB::table('idempotency_keys')->count());
    }
}
