<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

/**
 * Describes a payment evidence file already persisted to private storage.
 *
 * The Orchestrator stores the file before opening the business
 * transaction, then hands this DTO to SubmitPaymentEvidenceAction. The
 * Action is therefore free of any filesystem dependency.
 */
final readonly class StoredEvidenceData
{
    public function __construct(
        public string $documentId,
        public string $disk,
        public string $path,
        public string $originalFilename,
        public string $detectedMimeType,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}
