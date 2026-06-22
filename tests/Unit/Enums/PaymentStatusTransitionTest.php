<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTransitionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: PaymentStatus, 1: PaymentStatus, 2: bool}>
     */
    public static function transitions(): iterable
    {
        // happy paths
        yield 'pending -> under_review' => [PaymentStatus::Pending, PaymentStatus::UnderReview, true];
        yield 'pending -> cancelled' => [PaymentStatus::Pending, PaymentStatus::Cancelled, true];
        yield 'under_review -> approved' => [PaymentStatus::UnderReview, PaymentStatus::Approved, true];
        yield 'under_review -> rejected' => [PaymentStatus::UnderReview, PaymentStatus::Rejected, true];
        yield 'under_review -> cancelled' => [PaymentStatus::UnderReview, PaymentStatus::Cancelled, true];
        yield 'approved -> refunded' => [PaymentStatus::Approved, PaymentStatus::Refunded, true];

        // opposite operations must fail, not be silent no-ops
        yield 'approved -> rejected (forbidden)' => [PaymentStatus::Approved, PaymentStatus::Rejected, false];
        yield 'rejected -> approved (forbidden)' => [PaymentStatus::Rejected, PaymentStatus::Approved, false];

        // skipping under_review
        yield 'pending -> approved (forbidden)' => [PaymentStatus::Pending, PaymentStatus::Approved, false];
        yield 'pending -> rejected (forbidden)' => [PaymentStatus::Pending, PaymentStatus::Rejected, false];

        // terminal reverts
        yield 'cancelled -> pending' => [PaymentStatus::Cancelled, PaymentStatus::Pending, false];
        yield 'refunded -> approved' => [PaymentStatus::Refunded, PaymentStatus::Approved, false];
    }

    #[DataProvider('transitions')]
    public function test_can_transition_to_matches_matrix(
        PaymentStatus $current,
        PaymentStatus $next,
        bool $expected,
    ): void {
        $this->assertSame($expected, $current->canTransitionTo($next));
    }

    public function test_terminal_states(): void
    {
        $this->assertTrue(PaymentStatus::Rejected->isTerminal());
        $this->assertTrue(PaymentStatus::Cancelled->isTerminal());
        $this->assertTrue(PaymentStatus::Refunded->isTerminal());

        $this->assertFalse(PaymentStatus::Pending->isTerminal());
        $this->assertFalse(PaymentStatus::UnderReview->isTerminal());
        $this->assertFalse(PaymentStatus::Approved->isTerminal());
    }
}
