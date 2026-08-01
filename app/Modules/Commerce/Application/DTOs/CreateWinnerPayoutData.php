<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class CreateWinnerPayoutData
{
    public function __construct(
        public string $gameId,
        public int $actorUserId,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
        public WinnerPayoutDestinationData $destination,
    ) {}
}
