<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

final readonly class ProcessGatewayWebhookResult
{
    public function __construct(
        public string $webhookId,
        public string $provider,
        public string $providerEventId,
        public PaymentGatewayTransactionStatus $status,
        public ?string $transactionId,
        public bool $wasAlreadyProcessed,
        public bool $wasSettlementApplied,
        public ?CarbonImmutable $processedAt,
    ) {}
}
