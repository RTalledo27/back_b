<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\DTOs\ExpireOrderOutcome;
use App\Modules\Commerce\Application\DTOs\ExpireOrderResult;
use PHPUnit\Framework\TestCase;

final class ExpireOrderResultTest extends TestCase
{
    public function test_only_expired_outcome_was_transition_applied(): void
    {
        $this->assertTrue(ExpireOrderOutcome::Expired->wasTransitionApplied());
        $this->assertFalse(ExpireOrderOutcome::AlreadyExpired->wasTransitionApplied());
        $this->assertFalse(ExpireOrderOutcome::SkippedStateChanged->wasTransitionApplied());
        $this->assertFalse(ExpireOrderOutcome::NotDue->wasTransitionApplied());
    }

    public function test_result_delegates_was_transition_applied_to_outcome(): void
    {
        $expired = new ExpireOrderResult(
            orderId: 'o', paymentId: 'p', gameId: 'g', userId: 1,
            gameNumberIds: ['gn1'], numbers: [3],
            expiredAt: '2026-06-20T13:00:00+00:00',
            outcome: ExpireOrderOutcome::Expired,
        );

        $alreadyExpired = new ExpireOrderResult(
            orderId: 'o', paymentId: 'p', gameId: 'g', userId: 1,
            gameNumberIds: [], numbers: [],
            expiredAt: '2026-06-20T13:00:00+00:00',
            outcome: ExpireOrderOutcome::AlreadyExpired,
        );

        $this->assertTrue($expired->wasTransitionApplied());
        $this->assertFalse($alreadyExpired->wasTransitionApplied());
    }
}
