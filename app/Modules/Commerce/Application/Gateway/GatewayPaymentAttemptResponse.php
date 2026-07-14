<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use Carbon\CarbonImmutable;

final readonly class GatewayPaymentAttemptResponse
{
    public function __construct(
        public string $attemptId,
        public string $provider,
        public PaymentGatewayTransactionStatus $status,
        public int $amountCents,
        public string $currency,
        public ?string $checkoutUrl,
        public ?CarbonImmutable $expiresAt,
    ) {}

    public static function fromModel(PaymentGatewayAttempt $attempt): self
    {
        return new self(
            attemptId: $attempt->id,
            provider: $attempt->provider,
            status: PaymentGatewayTransactionStatus::from($attempt->status),
            amountCents: $attempt->amount_cents,
            currency: $attempt->currency,
            checkoutUrl: $attempt->checkout_url,
            expiresAt: $attempt->expires_at?->toImmutable(),
        );
    }
}
