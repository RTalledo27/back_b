<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Gateway;

use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayTransactionStatus;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayWebhookNormalizer;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayWebhookPayload;
use Carbon\CarbonImmutable;
use Throwable;

final class FakePaymentGatewayWebhookNormalizer implements PaymentGatewayWebhookNormalizer
{
    public function normalize(
        string $provider,
        string $rawPayload,
        array $headers = [],
    ): PaymentGatewayWebhookPayload {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
            $status = PaymentGatewayTransactionStatus::tryFrom((string) ($data['status'] ?? ''));

            if ($status === null) {
                throw PaymentGatewayException::malformedWebhook('Webhook status is not supported.');
            }

            foreach (['provider_event_id', 'event_type', 'amount_cents', 'currency', 'occurred_at'] as $field) {
                if (! array_key_exists($field, $data)) {
                    throw PaymentGatewayException::malformedWebhook("Webhook field [{$field}] is required.");
                }
            }

            return new PaymentGatewayWebhookPayload(
                provider: trim($provider),
                providerEventId: (string) $data['provider_event_id'],
                eventType: (string) $data['event_type'],
                status: $status,
                amountCents: (int) $data['amount_cents'],
                currency: strtoupper((string) $data['currency']),
                payloadHash: hash('sha256', $rawPayload),
                occurredAt: CarbonImmutable::parse((string) $data['occurred_at']),
                signatureVerified: filter_var(
                    $headers['signature_verified'] ?? false,
                    FILTER_VALIDATE_BOOL,
                ),
            );
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw PaymentGatewayException::malformedWebhook($exception->getMessage());
        }
    }
}
