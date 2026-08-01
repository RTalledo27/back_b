<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

final readonly class RecordGamePrizeFundingData
{
    public function __construct(
        public string $gameId,
        public int $actorUserId,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
        public string $documentId,
        public string $documentDisk,
        public string $documentPath,
        public string $documentOriginalFilename,
        public string $documentMimeType,
        public int $documentSizeBytes,
        public string $documentSha256,
    ) {}
}
