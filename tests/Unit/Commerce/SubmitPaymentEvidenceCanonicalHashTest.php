<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\Support\IdempotencyContext;
use PHPUnit\Framework\TestCase;

/**
 * Canonical payload for SubmitPaymentEvidence must include the file
 * SHA-256, real MIME and size — so that the same Idempotency-Key reused
 * with a different file produces a payload mismatch (409), and so that
 * truly identical re-submissions replay the cached result.
 */
final class SubmitPaymentEvidenceCanonicalHashTest extends TestCase
{
    public function test_same_file_components_produce_same_hash(): void
    {
        $a = IdempotencyContext::make(1, 'POST', 'p', 'k1234567890abcdef', [
            'order_id' => 'o',
            'file_sha256' => str_repeat('a', 64),
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $b = IdempotencyContext::make(1, 'POST', 'p', 'k1234567890abcdef', [
            'order_id' => 'o',
            'file_sha256' => str_repeat('a', 64),
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $this->assertSame($a->payloadSha256, $b->payloadSha256);
    }

    public function test_different_file_sha256_breaks_the_hash(): void
    {
        $a = IdempotencyContext::make(1, 'POST', 'p', 'k1234567890abcdef', [
            'order_id' => 'o', 'file_sha256' => str_repeat('a', 64),
            'mime_type' => 'application/pdf', 'size_bytes' => 1024,
        ]);
        $b = IdempotencyContext::make(1, 'POST', 'p', 'k1234567890abcdef', [
            'order_id' => 'o', 'file_sha256' => str_repeat('b', 64),
            'mime_type' => 'application/pdf', 'size_bytes' => 1024,
        ]);

        $this->assertNotSame($a->payloadSha256, $b->payloadSha256);
    }

    public function test_different_mime_breaks_the_hash(): void
    {
        $a = IdempotencyContext::make(1, 'POST', 'p', 'k1234567890abcdef', [
            'order_id' => 'o', 'file_sha256' => str_repeat('a', 64),
            'mime_type' => 'application/pdf', 'size_bytes' => 1024,
        ]);
        $b = IdempotencyContext::make(1, 'POST', 'p', 'k1234567890abcdef', [
            'order_id' => 'o', 'file_sha256' => str_repeat('a', 64),
            'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ]);

        $this->assertNotSame($a->payloadSha256, $b->payloadSha256);
    }

    public function test_different_size_breaks_the_hash(): void
    {
        $a = IdempotencyContext::make(1, 'POST', 'p', 'k1234567890abcdef', [
            'order_id' => 'o', 'file_sha256' => str_repeat('a', 64),
            'mime_type' => 'application/pdf', 'size_bytes' => 1024,
        ]);
        $b = IdempotencyContext::make(1, 'POST', 'p', 'k1234567890abcdef', [
            'order_id' => 'o', 'file_sha256' => str_repeat('a', 64),
            'mime_type' => 'application/pdf', 'size_bytes' => 2048,
        ]);

        $this->assertNotSame($a->payloadSha256, $b->payloadSha256);
    }
}
