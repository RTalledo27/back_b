<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway\Actions;

use App\Modules\Commerce\Application\Gateway\GatewayWebhookRecordRequest;
use App\Modules\Commerce\Application\Gateway\GatewayWebhookRecordResponse;
use App\Modules\Commerce\Application\Gateway\GatewayWebhookSignatureException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayProviderRegistry;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayWebhookData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayWebhookNormalizer;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayWebhookVerifier;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayWebhookAction;
use Carbon\CarbonImmutable;

final class RecordGatewayWebhookNotificationAction
{
    public function __construct(
        private readonly PaymentGatewayProviderRegistry $providers,
        private readonly PaymentGatewayWebhookVerifier $verifier,
        private readonly PaymentGatewayWebhookNormalizer $normalizer,
        private readonly RecordPaymentGatewayWebhookAction $recordWebhook,
    ) {}

    public function execute(GatewayWebhookRecordRequest $request): GatewayWebhookRecordResponse
    {
        $this->providers->get($request->provider);

        $now = $request->now ?? CarbonImmutable::now('UTC');
        $secret = (string) config('payment_gateways.credentials.webhook_secret', '');
        $tolerance = (int) config('payment_gateways.webhook_tolerance_seconds', 300);

        if (! $this->verifier->verify($request->rawPayload, $request->signature, $secret, $now, $tolerance)) {
            throw new GatewayWebhookSignatureException;
        }

        $payload = $this->normalizer->normalize(
            provider: $request->provider,
            rawPayload: $request->rawPayload,
            headers: array_merge($request->headers, ['signature_verified' => 'true']),
        );

        $webhook = $this->recordWebhook->execute(new PaymentGatewayWebhookData(
            provider: $payload->provider,
            providerEventId: $payload->providerEventId,
            eventType: $payload->eventType,
            signatureVerified: $payload->signatureVerified,
            payloadHash: $payload->payloadHash,
        ));

        return GatewayWebhookRecordResponse::fromModel($webhook);
    }
}
