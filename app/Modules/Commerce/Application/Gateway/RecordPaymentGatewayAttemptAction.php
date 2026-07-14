<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class RecordPaymentGatewayAttemptAction
{
    public function execute(PaymentGatewayAttemptData $data): PaymentGatewayAttempt
    {
        return DB::transaction(function () use ($data): PaymentGatewayAttempt {
            $now = now();

            PaymentGatewayAttempt::query()->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'order_id' => $data->orderId,
                'payment_id' => $data->paymentId,
                'provider' => $data->provider,
                'environment' => $data->environment,
                'idempotency_key_hash' => $data->idempotencyKeyHash,
                'request_fingerprint' => $data->requestFingerprint,
                'status' => $data->status,
                'amount_cents' => $data->amountCents,
                'currency' => $data->currency,
                'provider_attempt_id' => $data->providerAttemptId,
                'checkout_url' => $data->checkoutUrl,
                'expires_at' => $data->expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $attempt = PaymentGatewayAttempt::query()
                ->where('provider', $data->provider)
                ->where('idempotency_key_hash', $data->idempotencyKeyHash)
                ->lockForUpdate()
                ->first();

            if ($attempt === null) {
                throw new LogicException('The payment gateway attempt could not be recorded.');
            }

            if (! hash_equals($attempt->request_fingerprint, $data->requestFingerprint)) {
                throw PaymentGatewayException::idempotencyConflict();
            }

            return $attempt;
        });
    }
}
