<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Single semantic exception for "the order/payment state forbids accepting
 * this evidence". Named factories make the cause explicit at the throw
 * site and in error messages without proliferating exception classes.
 */
final class EvidenceRejected extends DomainException
{
    public static function orderExpired(): self
    {
        return new self('Order has expired and no longer accepts payment evidence.');
    }

    public static function orderNotPending(OrderStatus $current): self
    {
        return new self(
            "Order must be pending to submit evidence (current: {$current->value})."
        );
    }

    public static function paymentNotPending(PaymentStatus $current): self
    {
        return new self(
            "Payment must be pending to submit evidence (current: {$current->value})."
        );
    }

    public static function differentEvidenceForSubmittedOrder(): self
    {
        return new self(
            'A different payment evidence has already been submitted for this order. '
            .'Submit the same file or wait for the administrative decision.'
        );
    }

    public static function noActiveReservations(): self
    {
        return new self('Order has no active number reservations.');
    }
}
