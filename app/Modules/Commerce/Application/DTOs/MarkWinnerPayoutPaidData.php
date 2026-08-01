<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class MarkWinnerPayoutPaidData
{
    public function __construct(
        public string $payoutId,
        public int $actorUserId,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
        public string $externalReference,
        public string $documentId,
        public string $documentDisk,
        public string $documentPath,
        public string $documentOriginalFilename,
        public string $documentMimeType,
        public int $documentSizeBytes,
        public string $documentSha256,
    ) {}
}
