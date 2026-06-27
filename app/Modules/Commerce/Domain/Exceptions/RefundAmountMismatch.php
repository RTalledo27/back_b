<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class RefundAmountMismatch extends DomainException
{
    public static function centsOrCurrencyMismatch(
        int $paymentCents,
        string $paymentCurrency,
        int $orderCents,
        string $orderCurrency,
    ): self {
        return new self(
            "Payment amount ({$paymentCents} {$paymentCurrency}) does not match "
            ."order total ({$orderCents} {$orderCurrency}). Cannot refund."
        );
    }
}
