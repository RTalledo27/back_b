<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

final readonly class PaymentGatewayCreateAttemptResult
{
    public function __construct(
        public string $provider,
        public string $providerAttemptId,
        public PaymentGatewayTransactionStatus $status,
        public int $amountCents,
        public string $currency,
        public ?string $checkoutUrl,
        public ?CarbonImmutable $expiresAt,
        public CarbonImmutable $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_attempt_id' => $this->providerAttemptId,
            'status' => $this->status->value,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'checkout_url' => $this->checkoutUrl,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }
}
