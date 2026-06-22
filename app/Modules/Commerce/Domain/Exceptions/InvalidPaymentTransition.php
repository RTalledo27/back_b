<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Shared\Domain\Exceptions\DomainException;

final class InvalidPaymentTransition extends DomainException
{
    public static function from(PaymentStatus $current, PaymentStatus $next): self
    {
        return new self(
            "Cannot transition payment from {$current->value} to {$next->value}."
        );
    }
}
