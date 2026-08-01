<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

final readonly class WinnerIdentityDocumentData
{
    public function __construct(
        public string $documentType,
        public string $documentId,
        public string $disk,
        public string $path,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}
