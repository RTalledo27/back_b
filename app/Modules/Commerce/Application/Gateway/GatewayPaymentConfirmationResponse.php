<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayTransaction;
use Carbon\CarbonImmutable;

final readonly class GatewayPaymentConfirmationResponse
{
    public function __construct(
        public string $transactionId,
        public string $provider,
        public string $providerTransactionId,
        public PaymentGatewayTransactionStatus $status,
        public int $amountCents,
        public string $currency,
        public ?CarbonImmutable $authorizedAt,
        public ?CarbonImmutable $capturedAt,
        public ?CarbonImmutable $failedAt,
    ) {}

    public static function fromModel(PaymentGatewayTransaction $transaction): self
    {
        return new self(
            transactionId: $transaction->id,
            provider: $transaction->provider,
            providerTransactionId: $transaction->provider_transaction_id,
            status: PaymentGatewayTransactionStatus::from($transaction->status),
            amountCents: $transaction->amount_cents,
            currency: $transaction->currency,
            authorizedAt: $transaction->authorized_at?->toImmutable(),
            capturedAt: $transaction->captured_at?->toImmutable(),
            failedAt: $transaction->failed_at?->toImmutable(),
        );
    }
}
