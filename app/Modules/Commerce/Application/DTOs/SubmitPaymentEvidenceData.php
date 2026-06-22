<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

/**
 * Validated business input for SubmitPaymentEvidenceAction.
 *
 * Intentionally minimal: never carries Request, UploadedFile, disk,
 * path, client-supplied MIME, status or expiration. The Orchestrator
 * derives the StoredEvidenceData and passes it to the Action separately
 * once the file has been validated and persisted to the private disk.
 */
final readonly class SubmitPaymentEvidenceData
{
    public function __construct(
        public string $orderId,
        public int $userId,
    ) {}
}
