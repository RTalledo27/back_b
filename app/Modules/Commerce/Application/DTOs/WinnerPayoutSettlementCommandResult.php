<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class WinnerPayoutSettlementCommandResult implements CommandResult
{
    public function __construct(
        public string $payoutId,
        public string $resourceId,
        public string $status,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'payout_id' => $this->payoutId,
            'resource_id' => $this->resourceId,
            'status' => $this->status,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            payoutId: (string) $payload['payout_id'],
            resourceId: (string) $payload['resource_id'],
            status: (string) $payload['status'],
        );
    }
}
