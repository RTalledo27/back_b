<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\DTOs\SubmitPaymentEvidenceResult;
use PHPUnit\Framework\TestCase;

final class SubmitPaymentEvidenceResultTest extends TestCase
{
    public function test_to_array_and_from_array_round_trip(): void
    {
        $original = new SubmitPaymentEvidenceResult(
            orderId: 'o',
            paymentId: 'p',
            documentId: 'd',
            orderStatus: 'payment_submitted',
            paymentStatus: 'under_review',
            submittedAt: '2026-06-20T13:00:00+00:00',
            originalFilename: 'receipt.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 1024,
            sha256: str_repeat('a', 64),
        );

        $payload = $original->toArray();
        $rehydrated = SubmitPaymentEvidenceResult::fromArray($payload);

        $this->assertEquals($original, $rehydrated);
        $this->assertSame($payload, $rehydrated->toArray());
    }

    public function test_payload_does_not_expose_disk_or_path(): void
    {
        $result = new SubmitPaymentEvidenceResult(
            orderId: 'o', paymentId: 'p', documentId: 'd',
            orderStatus: 'payment_submitted', paymentStatus: 'under_review',
            submittedAt: 't', originalFilename: 'r.pdf',
            mimeType: 'application/pdf', sizeBytes: 1, sha256: str_repeat('a', 64),
        );

        $payload = $result->toArray();

        $this->assertArrayNotHasKey('disk', $payload);
        $this->assertArrayNotHasKey('path', $payload);
    }
}
