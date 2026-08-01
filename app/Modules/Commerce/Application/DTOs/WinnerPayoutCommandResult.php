<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class WinnerPayoutCommandResult implements CommandResult
{
    public function __construct(
        public string $payoutId,
        public string $status,
        public bool $wasTransitionApplied = true,
        public ?string $attemptId = null,
        public ?string $documentId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'payout_id' => $this->payoutId,
            'status' => $this->status,
            'was_transition_applied' => $this->wasTransitionApplied,
            'attempt_id' => $this->attemptId,
            'document_id' => $this->documentId,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            payoutId: (string) $payload['payout_id'],
            status: (string) $payload['status'],
            wasTransitionApplied: (bool) ($payload['was_transition_applied'] ?? true),
            attemptId: isset($payload['attempt_id']) ? (string) $payload['attempt_id'] : null,
            documentId: isset($payload['document_id']) ? (string) $payload['document_id'] : null,
        );
    }
}
