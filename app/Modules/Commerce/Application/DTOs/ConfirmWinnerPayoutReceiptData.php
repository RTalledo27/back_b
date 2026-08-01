<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class ConfirmWinnerPayoutReceiptData
{
    public function __construct(
        public string $winnerId,
        public int $actorUserId,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
    ) {}
}
