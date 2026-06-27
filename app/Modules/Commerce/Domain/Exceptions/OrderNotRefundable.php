<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class OrderNotRefundable extends DomainException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function orderNotPaid(string $orderId, string $currentStatus): self
    {
        return new self(
            "Order {$orderId} cannot be refunded: status is '{$currentStatus}', expected 'paid'.",
            'order_not_paid',
        );
    }

    public static function paymentNotApproved(string $paymentId, string $currentStatus): self
    {
        return new self(
            "Payment {$paymentId} cannot be refunded: status is '{$currentStatus}', expected 'approved'.",
            'payment_not_approved',
        );
    }

    public static function paymentMissing(string $orderId): self
    {
        return new self(
            "Order {$orderId} has no associated payment.",
            'payment_not_approved',
        );
    }

    public static function gameNotRefundable(string $gameId, string $currentStatus): self
    {
        return new self(
            "Game {$gameId} is in status '{$currentStatus}', which does not allow refunds.",
            'game_not_refundable',
        );
    }

    public static function entryNotConfirmed(string $entryId, string $currentStatus): self
    {
        return new self(
            "GameEntry {$entryId} is in status '{$currentStatus}', expected 'confirmed'.",
            'entry_not_refundable',
        );
    }

    public static function noAllocationsFound(string $orderId): self
    {
        return new self(
            "Order {$orderId} has no purchase allocations and cannot be refunded.",
            'entry_not_refundable',
        );
    }
}
