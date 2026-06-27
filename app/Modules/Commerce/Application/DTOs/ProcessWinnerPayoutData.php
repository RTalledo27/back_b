<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class ProcessWinnerPayoutData
{
    public function __construct(
        public string $gameId,
        public int $actorUserId,
        public string $externalReference,
        public ?string $notes,
        public string $idempotencyKeyHash,
        // Document metadata (file already stored to disk by caller)
        public string $documentDisk,
        public string $documentPath,
        public string $documentOriginalFilename,
        public string $documentMimeType,
        public int $documentSizeBytes,
        public string $documentSha256,
    ) {}
}
