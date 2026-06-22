<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Storage;

/**
 * Immutable snapshot of an uploaded file as inspected on the server.
 * Used by the Orchestrator to feed the idempotency hash before any
 * persistent storage happens.
 */
final readonly class EvidenceAnalysis
{
    public function __construct(
        public string $sha256,
        public string $mimeType,
        public int $sizeBytes,
        public string $extension,
    ) {}
}
