<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\DTOs\ApprovePaymentResult;
use PHPUnit\Framework\TestCase;

final class ApprovePaymentResultTest extends TestCase
{
    public function test_to_array_and_from_array_round_trip(): void
    {
        $original = new ApprovePaymentResult(
            paymentId: 'p', orderId: 'o', gameId: 'g',
            buyerUserId: 7, reviewerUserId: 1,
            orderStatus: 'paid', paymentStatus: 'approved',
            paidAt: '2026-06-20T13:00:00+00:00',
            reviewedAt: '2026-06-20T13:00:01+00:00',
            gameEntryIds: ['e1', 'e2'],
            purchaseAllocationIds: ['a1', 'a2'],
            gameNumberIds: ['gn1', 'gn2'],
            numbers: [3, 8],
            wasTransitionApplied: true,
        );

        $rehydrated = ApprovePaymentResult::fromArray($original->toArray());

        $this->assertEquals($original, $rehydrated);
        $this->assertTrue($rehydrated->wasTransitionApplied);
    }

    public function test_from_array_defaults_was_transition_applied_to_false_when_missing(): void
    {
        $payload = (new ApprovePaymentResult(
            paymentId: 'p', orderId: 'o', gameId: 'g',
            buyerUserId: 1, reviewerUserId: 1,
            orderStatus: 'paid', paymentStatus: 'approved',
            paidAt: 't1', reviewedAt: 't2',
            gameEntryIds: [], purchaseAllocationIds: [],
            gameNumberIds: [], numbers: [],
            wasTransitionApplied: true,
        ))->toArray();

        unset($payload['was_transition_applied']);

        $this->assertFalse(ApprovePaymentResult::fromArray($payload)->wasTransitionApplied);
    }
}
