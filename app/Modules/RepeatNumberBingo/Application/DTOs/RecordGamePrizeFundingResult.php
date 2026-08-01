<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

final readonly class RecordGamePrizeFundingResult
{
    public function __construct(
        public string $fundingId,
        public string $gameId,
        public string $status,
        public int $amountCents,
        public string $currency,
        public string $documentId,
        public string $fundedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'funding_id' => $this->fundingId,
            'game_id' => $this->gameId,
            'status' => $this->status,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'document_id' => $this->documentId,
            'funded_at' => $this->fundedAt,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            fundingId: (string) $payload['funding_id'],
            gameId: (string) $payload['game_id'],
            status: (string) $payload['status'],
            amountCents: (int) $payload['amount_cents'],
            currency: (string) $payload['currency'],
            documentId: (string) $payload['document_id'],
            fundedAt: (string) $payload['funded_at'],
        );
    }
}
