<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayWebhook;
use Carbon\CarbonImmutable;

final readonly class GatewayWebhookRecordResponse
{
    public function __construct(
        public string $webhookId,
        public string $provider,
        public string $providerEventId,
        public string $eventType,
        public bool $signatureVerified,
        public CarbonImmutable $createdAt,
    ) {}

    public static function fromModel(PaymentGatewayWebhook $webhook): self
    {
        return new self(
            webhookId: $webhook->id,
            provider: $webhook->provider,
            providerEventId: $webhook->provider_event_id,
            eventType: $webhook->event_type,
            signatureVerified: $webhook->signature_verified,
            createdAt: $webhook->created_at->toImmutable(),
        );
    }
}
