<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\DTOs\RejectPaymentResult;
use PHPUnit\Framework\TestCase;

final class RejectPaymentResultTest extends TestCase
{
    public function test_to_array_and_from_array_round_trip(): void
    {
        $original = new RejectPaymentResult(
            paymentId: 'p', orderId: 'o', gameId: 'g',
            buyerUserId: 7, reviewerUserId: 1,
            orderStatus: 'rejected', paymentStatus: 'rejected',
            reviewedAt: '2026-06-20T13:00:00+00:00',
            reason: 'evidence is illegible',
            releasedGameNumberIds: ['gn1', 'gn2'],
            releasedNumbers: [3, 8],
            wasTransitionApplied: true,
        );

        $rehydrated = RejectPaymentResult::fromArray($original->toArray());

        $this->assertEquals($original, $rehydrated);
        $this->assertTrue($rehydrated->wasTransitionApplied);
    }
}
