<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway\Actions;

use App\Modules\Commerce\Application\Gateway\GatewayPaymentConfirmationRequest;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentConfirmationResponse;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayConfirmData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayProviderRegistry;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayTransactionData;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayTransactionAction;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayTransaction;
use Illuminate\Support\Facades\DB;

final class ConfirmGatewayPaymentAttemptAction
{
    public function __construct(
        private readonly PaymentGatewayProviderRegistry $providers,
        private readonly RecordPaymentGatewayTransactionAction $recordTransaction,
    ) {}

    public function execute(GatewayPaymentConfirmationRequest $request): GatewayPaymentConfirmationResponse
    {
        return DB::transaction(function () use ($request): GatewayPaymentConfirmationResponse {
            /** @var PaymentGatewayAttempt $attempt */
            $attempt = PaymentGatewayAttempt::query()
                ->whereKey($request->attemptId)
                ->lockForUpdate()
                ->firstOrFail();

            $existingTransaction = PaymentGatewayTransaction::query()
                ->where('payment_gateway_attempt_id', $attempt->id)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($existingTransaction !== null) {
                return GatewayPaymentConfirmationResponse::fromModel($existingTransaction);
            }

            if ($attempt->provider_attempt_id === null) {
                throw PaymentGatewayException::attemptNotFound($attempt->id);
            }

            $providerResult = $this->providers
                ->get($attempt->provider)
                ->confirm(new PaymentGatewayConfirmData(
                    providerAttemptId: $attempt->provider_attempt_id,
                    idempotencyKeyHash: $request->idempotencyKeyHash,
                    requestFingerprint: $request->requestFingerprint,
                ));

            if (
                $providerResult->provider !== $attempt->provider
                || $providerResult->providerAttemptId !== $attempt->provider_attempt_id
                || $providerResult->amountCents !== $attempt->amount_cents
                || $providerResult->currency !== $attempt->currency
            ) {
                throw PaymentGatewayException::providerFailure(
                    'The gateway confirmation does not match the recorded attempt.',
                );
            }

            $transaction = $this->recordTransaction->execute(new PaymentGatewayTransactionData(
                paymentGatewayAttemptId: $attempt->id,
                paymentId: $attempt->payment_id,
                provider: $providerResult->provider,
                providerTransactionId: $providerResult->providerTransactionId,
                status: $providerResult->status->value,
                amountCents: $providerResult->amountCents,
                currency: $providerResult->currency,
                authorizedAt: $providerResult->authorizedAt,
                capturedAt: $providerResult->capturedAt,
                failedAt: $providerResult->failedAt,
            ));

            return GatewayPaymentConfirmationResponse::fromModel($transaction);
        });
    }
}
