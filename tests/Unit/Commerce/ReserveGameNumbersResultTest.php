<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\DTOs\ReserveGameNumbersResult;
use PHPUnit\Framework\TestCase;

final class ReserveGameNumbersResultTest extends TestCase
{
    public function test_to_array_and_from_array_round_trip(): void
    {
        $original = new ReserveGameNumbersResult(
            orderId: 'order-id',
            gameId: 'game-id',
            userId: 42,
            paymentId: 'payment-id',
            numbers: [3, 8, 21],
            gameNumberIds: ['gn-1', 'gn-2', 'gn-3'],
            reservationIds: ['r-1', 'r-2', 'r-3'],
            subtotalCents: 1500,
            totalCents: 1500,
            currency: 'PEN',
            expiresAt: '2026-06-20T13:00:00+00:00',
        );

        $payload = $original->toArray();
        $rehydrated = ReserveGameNumbersResult::fromArray($payload);

        $this->assertEquals($original, $rehydrated);
        $this->assertSame($payload, $rehydrated->toArray());
    }

    public function test_from_array_normalises_numeric_types(): void
    {
        // Simulates a JSONB roundtrip where some ints could come back as strings.
        $payload = [
            'order_id' => 'o',
            'game_id' => 'g',
            'user_id' => '7',
            'payment_id' => 'p',
            'numbers' => ['1', '2'],
            'game_number_ids' => ['x', 'y'],
            'reservation_ids' => ['r1', 'r2'],
            'subtotal_cents' => '200',
            'total_cents' => '200',
            'currency' => 'PEN',
            'expires_at' => 'now',
        ];

        $result = ReserveGameNumbersResult::fromArray($payload);

        $this->assertSame(7, $result->userId);
        $this->assertSame([1, 2], $result->numbers);
        $this->assertSame(200, $result->totalCents);
    }
}
