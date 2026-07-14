<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

final readonly class PaymentGatewayWebhookPayload
{
    public function __construct(
        public string $provider,
        public string $providerEventId,
        public string $eventType,
        public PaymentGatewayTransactionStatus $status,
        public int $amountCents,
        public string $currency,
        public string $payloadHash,
        public CarbonImmutable $occurredAt,
        public bool $signatureVerified,
    ) {}
}
