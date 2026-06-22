<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Idempotency;

/**
 * @phpstan-type ResultPayload array<string, mixed>
 */
final readonly class IdempotencyClaim
{
    /**
     * @param  ResultPayload|null  $resultPayload
     */
    public function __construct(
        public IdempotencyClaimResult $result,
        public ?string $rowId = null,
        public ?array $resultPayload = null,
    ) {}

    public static function claimed(string $rowId): self
    {
        return new self(IdempotencyClaimResult::Claimed, rowId: $rowId);
    }

    /**
     * @param  ResultPayload  $payload
     */
    public static function completed(array $payload): self
    {
        return new self(IdempotencyClaimResult::CompletedSamePayload, resultPayload: $payload);
    }

    public static function payloadMismatch(): self
    {
        return new self(IdempotencyClaimResult::PayloadMismatch);
    }

    public static function inProgress(): self
    {
        return new self(IdempotencyClaimResult::InProgress);
    }
}
