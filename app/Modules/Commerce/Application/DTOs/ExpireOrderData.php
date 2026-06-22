<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class ExpireOrderData
{
    public function __construct(
        public string $orderId,
    ) {}
}
