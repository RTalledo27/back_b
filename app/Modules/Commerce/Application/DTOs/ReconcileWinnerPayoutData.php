<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class ReconcileWinnerPayoutData
{
    public function __construct(
        public string $payoutId,
        public int $actorUserId,
        public string $resultCode,
        public ?string $reference,
        public ?string $notes,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
    ) {}
}
