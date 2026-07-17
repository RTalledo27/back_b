<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayWebhook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class RecordPaymentGatewayWebhookAction
{
    public function execute(PaymentGatewayWebhookData $data): PaymentGatewayWebhook
    {
        return DB::transaction(function () use ($data): PaymentGatewayWebhook {
            $now = now();

            PaymentGatewayWebhook::query()->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'provider' => $data->provider,
                'provider_event_id' => $data->providerEventId,
                'event_type' => $data->eventType,
                'signature_verified' => $data->signatureVerified,
                'payload_hash' => $data->payloadHash,
                'provider_attempt_id' => $data->providerAttemptId,
                'provider_transaction_id' => $data->providerTransactionId,
                'normalized_status' => $data->normalizedStatus?->value,
                'amount_cents' => $data->amountCents,
                'currency' => $data->currency,
                'environment' => $data->environment,
                'occurred_at' => $data->occurredAt,
                'processing_attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $webhook = PaymentGatewayWebhook::query()
                ->where('provider', $data->provider)
                ->where('provider_event_id', $data->providerEventId)
                ->lockForUpdate()
                ->first();

            if ($webhook === null) {
                throw new LogicException('The payment gateway webhook could not be recorded.');
            }

            if (! self::matches($webhook, $data)) {
                throw PaymentGatewayException::idempotencyConflict();
            }

            return $webhook;
        });
    }

    private static function matches(PaymentGatewayWebhook $webhook, PaymentGatewayWebhookData $data): bool
    {
        return $webhook->event_type === $data->eventType
            && $webhook->signature_verified === $data->signatureVerified
            && $webhook->payload_hash === $data->payloadHash
            && $webhook->provider_attempt_id === $data->providerAttemptId
            && $webhook->provider_transaction_id === $data->providerTransactionId
            && $webhook->normalized_status === $data->normalizedStatus?->value
            && $webhook->amount_cents === $data->amountCents
            && $webhook->currency === $data->currency
            && $webhook->environment === $data->environment
            && ($webhook->occurred_at?->equalTo($data->occurredAt) ?? $data->occurredAt === null);
    }
}
