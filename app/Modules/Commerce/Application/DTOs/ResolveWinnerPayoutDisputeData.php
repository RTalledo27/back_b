<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class ResolveWinnerPayoutDisputeData
{
    public function __construct(
        public string $disputeId,
        public int $actorUserId,
        public string $resolutionCode,
        public string $reasonCode,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
    ) {}
}
