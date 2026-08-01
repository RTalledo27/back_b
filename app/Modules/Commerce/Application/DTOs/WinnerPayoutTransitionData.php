<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class WinnerPayoutTransitionData
{
    public function __construct(
        public string $payoutId,
        public int $actorUserId,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
        public ?string $reasonCode = null,
        public ?string $externalReference = null,
    ) {}
}
