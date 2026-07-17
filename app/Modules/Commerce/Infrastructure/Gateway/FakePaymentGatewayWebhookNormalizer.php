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

            $headerEventId = isset($headers['X-Gateway-Event-Id'])
                ? self::optionalString($headers['X-Gateway-Event-Id'], 'X-Gateway-Event-Id')
                : null;

            if ($headerEventId !== null && $headerEventId !== (string) $data['provider_event_id']) {
                throw PaymentGatewayException::malformedWebhook('Webhook event identity does not match its signed header.');
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
                providerAttemptId: isset($data['provider_attempt_id'])
                    ? self::optionalString($data['provider_attempt_id'], 'provider_attempt_id')
                    : null,
                providerTransactionId: isset($data['provider_transaction_id'])
                    ? self::optionalString($data['provider_transaction_id'], 'provider_transaction_id')
                    : null,
                environment: isset($data['environment'])
                    ? self::optionalString($data['environment'], 'environment')
                    : null,
            );
        } catch (PaymentGatewayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw PaymentGatewayException::malformedWebhook($exception->getMessage());
        }
    }

    private static function optionalString(mixed $value, string $field): string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            throw PaymentGatewayException::malformedWebhook("Webhook field [{$field}] must not be empty.");
        }

        return $normalized;
    }
}
