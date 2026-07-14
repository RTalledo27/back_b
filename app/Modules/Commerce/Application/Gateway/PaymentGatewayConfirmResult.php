<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

final readonly class PaymentGatewayConfirmResult
{
    public function __construct(
        public string $provider,
        public string $providerAttemptId,
        public string $providerTransactionId,
        public PaymentGatewayTransactionStatus $status,
        public int $amountCents,
        public string $currency,
        public ?CarbonImmutable $authorizedAt,
        public ?CarbonImmutable $capturedAt,
        public ?CarbonImmutable $failedAt,
        public CarbonImmutable $processedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_attempt_id' => $this->providerAttemptId,
            'provider_transaction_id' => $this->providerTransactionId,
            'status' => $this->status->value,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'authorized_at' => $this->authorizedAt?->toIso8601String(),
            'captured_at' => $this->capturedAt?->toIso8601String(),
            'failed_at' => $this->failedAt?->toIso8601String(),
            'processed_at' => $this->processedAt->toIso8601String(),
        ];
    }
}
