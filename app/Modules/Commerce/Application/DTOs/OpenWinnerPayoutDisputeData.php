<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class OpenWinnerPayoutDisputeData
{
    public function __construct(
        public string $winnerId,
        public int $actorUserId,
        public string $reasonCode,
        public ?string $description,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
    ) {}
}
