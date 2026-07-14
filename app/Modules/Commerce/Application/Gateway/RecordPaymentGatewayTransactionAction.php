<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class RecordPaymentGatewayTransactionAction
{
    public function execute(PaymentGatewayTransactionData $data): PaymentGatewayTransaction
    {
        return DB::transaction(function () use ($data): PaymentGatewayTransaction {
            $now = now();

            PaymentGatewayTransaction::query()->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'payment_gateway_attempt_id' => $data->paymentGatewayAttemptId,
                'payment_id' => $data->paymentId,
                'provider' => $data->provider,
                'provider_transaction_id' => $data->providerTransactionId,
                'status' => $data->status,
                'amount_cents' => $data->amountCents,
                'currency' => $data->currency,
                'authorized_at' => $data->authorizedAt,
                'captured_at' => $data->capturedAt,
                'failed_at' => $data->failedAt,
                'raw_reference_hash' => $data->rawReferenceHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $transaction = PaymentGatewayTransaction::query()
                ->where('provider', $data->provider)
                ->where('provider_transaction_id', $data->providerTransactionId)
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                throw new LogicException('The payment gateway transaction could not be recorded.');
            }

            if (! self::matches($transaction, $data)) {
                throw PaymentGatewayException::idempotencyConflict();
            }

            return $transaction;
        });
    }

    private static function matches(
        PaymentGatewayTransaction $transaction,
        PaymentGatewayTransactionData $data,
    ): bool {
        return $transaction->payment_gateway_attempt_id === $data->paymentGatewayAttemptId
            && $transaction->payment_id === $data->paymentId
            && $transaction->status === $data->status
            && $transaction->amount_cents === $data->amountCents
            && $transaction->currency === $data->currency
            && $transaction->raw_reference_hash === $data->rawReferenceHash;
    }
}
