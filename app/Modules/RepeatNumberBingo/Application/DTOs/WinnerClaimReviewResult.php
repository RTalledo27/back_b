<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

final readonly class WinnerClaimReviewResult
{
    public function __construct(
        public string $claimId,
        public string $status,
        public string $reviewedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'claim_id' => $this->claimId,
            'status' => $this->status,
            'reviewed_at' => $this->reviewedAt,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            claimId: (string) $payload['claim_id'],
            status: (string) $payload['status'],
            reviewedAt: (string) $payload['reviewed_at'],
        );
    }
}
