<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

final readonly class WinnerClaimSubmissionResult
{
    public function __construct(
        public string $claimId,
        public string $claimReference,
        public string $status,
        public string $identitySubmittedAt,
        public int $documentCount,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'claim_id' => $this->claimId,
            'claim_reference' => $this->claimReference,
            'status' => $this->status,
            'identity_submitted_at' => $this->identitySubmittedAt,
            'document_count' => $this->documentCount,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            claimId: (string) $payload['claim_id'],
            claimReference: (string) $payload['claim_reference'],
            status: (string) $payload['status'],
            identitySubmittedAt: (string) $payload['identity_submitted_at'],
            documentCount: (int) $payload['document_count'],
        );
    }
}
