<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Infrastructure\Storage;

final readonly class WinnerIdentityDocumentAnalysis
{
    public function __construct(
        public string $sha256,
        public string $mimeType,
        public int $sizeBytes,
        public string $extension,
    ) {}
}
