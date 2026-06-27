<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class RefundNotFound extends DomainException
{
    public static function forOrder(string $orderId): self
    {
        return new self("No refund found for order {$orderId}.");
    }
}
