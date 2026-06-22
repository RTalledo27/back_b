<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\DTOs\StoredEvidenceData;
use PHPUnit\Framework\TestCase;

final class StoredEvidenceDataTest extends TestCase
{
    public function test_constructs_with_expected_fields(): void
    {
        $data = new StoredEvidenceData(
            documentId: 'doc',
            disk: 'payment_evidences',
            path: 'p/doc.pdf',
            originalFilename: 'receipt.pdf',
            detectedMimeType: 'application/pdf',
            sizeBytes: 100,
            sha256: str_repeat('a', 64),
        );

        $this->assertSame('doc', $data->documentId);
        $this->assertSame('payment_evidences', $data->disk);
        $this->assertSame('p/doc.pdf', $data->path);
        $this->assertSame('receipt.pdf', $data->originalFilename);
        $this->assertSame('application/pdf', $data->detectedMimeType);
        $this->assertSame(100, $data->sizeBytes);
        $this->assertSame(str_repeat('a', 64), $data->sha256);
    }
}
