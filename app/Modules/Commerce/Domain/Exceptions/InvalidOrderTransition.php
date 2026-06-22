<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Shared\Domain\Exceptions\DomainException;

final class InvalidOrderTransition extends DomainException
{
    public static function from(OrderStatus $current, OrderStatus $next): self
    {
        return new self(
            "Cannot transition order from {$current->value} to {$next->value}."
        );
    }
}
