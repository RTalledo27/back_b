<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\Support\IdempotencyContext;
use PHPUnit\Framework\TestCase;

final class IdempotencyContextCanonicalTest extends TestCase
{
    public function test_same_components_in_same_order_produce_same_hash(): void
    {
        $a = IdempotencyContext::make(1, 'POST', 'api/v1/games/g/reservations', 'key1234567890abcd', [
            'game_id' => 'g',
            'game_number_ids' => ['a', 'b', 'c'],
        ]);

        $b = IdempotencyContext::make(1, 'POST', 'api/v1/games/g/reservations', 'key1234567890abcd', [
            'game_id' => 'g',
            'game_number_ids' => ['a', 'b', 'c'],
        ]);

        $this->assertSame($a->payloadSha256, $b->payloadSha256);
    }

    public function test_caller_must_pre_sort_lists_for_hash_to_match(): void
    {
        // Controller sorts game_number_ids before passing them in — this is
        // the *caller's* responsibility, exercised by the controller. The
        // DTO does not normalise.
        $sortedAbc = IdempotencyContext::make(1, 'POST', 'api/v1/games/g/reservations', 'key1234567890abcd', [
            'game_id' => 'g',
            'game_number_ids' => ['a', 'b', 'c'],
        ]);

        $sortedAfterRotation = IdempotencyContext::make(1, 'POST', 'api/v1/games/g/reservations', 'key1234567890abcd', [
            'game_id' => 'g',
            'game_number_ids' => self::sortedClone(['c', 'a', 'b']),
        ]);

        $this->assertSame($sortedAbc->payloadSha256, $sortedAfterRotation->payloadSha256);
    }

    public function test_different_components_produce_different_hash(): void
    {
        $a = IdempotencyContext::make(1, 'POST', 'p', 'k1234567890abcdef', ['game_id' => 'g1']);
        $b = IdempotencyContext::make(1, 'POST', 'p', 'k1234567890abcdef', ['game_id' => 'g2']);

        $this->assertNotSame($a->payloadSha256, $b->payloadSha256);
    }

    public function test_method_is_uppercased(): void
    {
        $c = IdempotencyContext::make(1, 'post', 'p', 'k1234567890abcdef', []);

        $this->assertSame('POST', $c->method);
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function sortedClone(array $items): array
    {
        sort($items, SORT_STRING);

        return array_values($items);
    }
}
