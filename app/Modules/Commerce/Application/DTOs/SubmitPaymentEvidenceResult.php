<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

/**
 * Result returned by SubmitPaymentEvidenceAction. Used both for the fresh
 * response and for idempotent replay (rehydrated from JSONB in
 * idempotency_keys.result_payload). Never carries the internal storage
 * path or disk — those must not leak to the client.
 */
final readonly class SubmitPaymentEvidenceResult implements CommandResult
{
    public function __construct(
        public string $orderId,
        public string $paymentId,
        public string $documentId,
        public string $orderStatus,
        public string $paymentStatus,
        public string $submittedAt,
        public string $originalFilename,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'payment_id' => $this->paymentId,
            'document_id' => $this->documentId,
            'order_status' => $this->orderStatus,
            'payment_status' => $this->paymentStatus,
            'submitted_at' => $this->submittedAt,
            'original_filename' => $this->originalFilename,
            'mime_type' => $this->mimeType,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            orderId: (string) $payload['order_id'],
            paymentId: (string) $payload['payment_id'],
            documentId: (string) $payload['document_id'],
            orderStatus: (string) $payload['order_status'],
            paymentStatus: (string) $payload['payment_status'],
            submittedAt: (string) $payload['submitted_at'],
            originalFilename: (string) $payload['original_filename'],
            mimeType: (string) $payload['mime_type'],
            sizeBytes: (int) $payload['size_bytes'],
            sha256: (string) $payload['sha256'],
        );
    }
}
