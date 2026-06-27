<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class RefundOrderData
{
    public function __construct(
        public string $orderId,
        public int $actorUserId,
        public string $reason,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
    ) {}
}
