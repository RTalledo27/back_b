<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class ProcessWinnerPayoutResult
{
    public function __construct(
        public string $payoutId,
        public string $gameWinnerId,
        public string $gameId,
        public int $winnerUserId,
        public int $actorUserId,
        public int $amountCents,
        public string $currency,
        public string $method,
        public string $externalReference,
        public ?string $notes,
        public string $processedAt,
        public string $createdAt,
        // Document info (no disk, path, sha256 — those stay private)
        public string $documentId,
        public string $documentOriginalFilename,
        public string $documentMimeType,
        public int $documentSizeBytes,
        public string $documentCreatedAt,
        public bool $wasAlreadyProcessed,
    ) {}
}
