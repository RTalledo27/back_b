<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderStatusTransitionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: OrderStatus, 1: OrderStatus, 2: bool}>
     */
    public static function transitions(): iterable
    {
        // happy paths
        yield 'pending -> payment_submitted' => [OrderStatus::Pending, OrderStatus::PaymentSubmitted, true];
        yield 'pending -> expired' => [OrderStatus::Pending, OrderStatus::Expired, true];
        yield 'pending -> cancelled' => [OrderStatus::Pending, OrderStatus::Cancelled, true];
        yield 'payment_submitted -> paid' => [OrderStatus::PaymentSubmitted, OrderStatus::Paid, true];
        yield 'payment_submitted -> rejected' => [OrderStatus::PaymentSubmitted, OrderStatus::Rejected, true];
        yield 'payment_submitted -> cancelled' => [OrderStatus::PaymentSubmitted, OrderStatus::Cancelled, true];
        yield 'paid -> refunded' => [OrderStatus::Paid, OrderStatus::Refunded, true];

        // CRITICAL: payment_submitted does NOT auto-expire
        yield 'payment_submitted -> expired (forbidden)' => [OrderStatus::PaymentSubmitted, OrderStatus::Expired, false];

        // other forbidden jumps
        yield 'pending -> paid' => [OrderStatus::Pending, OrderStatus::Paid, false];
        yield 'pending -> rejected' => [OrderStatus::Pending, OrderStatus::Rejected, false];
        yield 'paid -> rejected' => [OrderStatus::Paid, OrderStatus::Rejected, false];
        yield 'rejected -> pending' => [OrderStatus::Rejected, OrderStatus::Pending, false];
        yield 'expired -> pending' => [OrderStatus::Expired, OrderStatus::Pending, false];
        yield 'cancelled -> paid' => [OrderStatus::Cancelled, OrderStatus::Paid, false];
        yield 'refunded -> paid' => [OrderStatus::Refunded, OrderStatus::Paid, false];
    }

    #[DataProvider('transitions')]
    public function test_can_transition_to_matches_matrix(
        OrderStatus $current,
        OrderStatus $next,
        bool $expected,
    ): void {
        $this->assertSame($expected, $current->canTransitionTo($next));
    }

    public function test_terminal_states(): void
    {
        $this->assertTrue(OrderStatus::Rejected->isTerminal());
        $this->assertTrue(OrderStatus::Expired->isTerminal());
        $this->assertTrue(OrderStatus::Cancelled->isTerminal());
        $this->assertTrue(OrderStatus::Refunded->isTerminal());

        $this->assertFalse(OrderStatus::Pending->isTerminal());
        $this->assertFalse(OrderStatus::PaymentSubmitted->isTerminal());
        $this->assertFalse(OrderStatus::Paid->isTerminal());
    }
}
