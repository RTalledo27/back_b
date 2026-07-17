<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway\Actions;

use App\Modules\Commerce\Application\Gateway\GatewayPaymentSettlementRequest;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayTransactionData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayTransactionStatus;
use App\Modules\Commerce\Application\Gateway\ProcessGatewayWebhookData;
use App\Modules\Commerce\Application\Gateway\ProcessGatewayWebhookResult;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayTransactionAction;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayTransaction;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayWebhook;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessGatewayWebhookAction
{
    public function __construct(
        private readonly RecordPaymentGatewayTransactionAction $recordTransaction,
        private readonly SettleGatewayPaidTransactionAction $settlement,
    ) {}

    public function execute(ProcessGatewayWebhookData $data): ProcessGatewayWebhookResult
    {
        try {
            $technicalResult = DB::transaction(
                fn (): ProcessGatewayWebhookResult => $this->processTechnicalState($data),
            );

            if ($technicalResult->wasAlreadyProcessed) {
                return $technicalResult;
            }

            return $this->markProcessed($data->webhookId, $technicalResult);
        } catch (Throwable $exception) {
            $this->markFailedSafely($data->webhookId, $exception);

            throw $exception;
        }
    }

    private function processTechnicalState(ProcessGatewayWebhookData $data): ProcessGatewayWebhookResult
    {
        /** @var PaymentGatewayWebhook $webhook */
        $webhook = PaymentGatewayWebhook::query()
            ->whereKey($data->webhookId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($webhook->processed_at !== null) {
            return $this->resultFromWebhook($webhook, wasAlreadyProcessed: true);
        }

        if (! $webhook->signature_verified) {
            throw PaymentGatewayException::webhookProcessingFailure(
                'The gateway webhook signature is not verified.',
            );
        }

        $status = PaymentGatewayTransactionStatus::tryFrom((string) $webhook->normalized_status);

        if (! in_array($status, [
            PaymentGatewayTransactionStatus::Authorized,
            PaymentGatewayTransactionStatus::Paid,
            PaymentGatewayTransactionStatus::Captured,
            PaymentGatewayTransactionStatus::Failed,
            PaymentGatewayTransactionStatus::Expired,
        ], true)) {
            throw PaymentGatewayException::webhookProcessingFailure(
                'The gateway webhook status is not supported for processing.',
            );
        }

        if (
            $webhook->provider_attempt_id === null
            || $webhook->provider_transaction_id === null
            || $webhook->amount_cents === null
            || $webhook->currency === null
            || $webhook->occurred_at === null
        ) {
            throw PaymentGatewayException::webhookProcessingFailure(
                'The gateway webhook does not contain sufficient normalized metadata.',
            );
        }

        $webhook->processing_attempts = ((int) $webhook->processing_attempts) + 1;
        $webhook->failed_at = null;
        $webhook->last_error = null;
        $webhook->save();

        /** @var PaymentGatewayAttempt $attempt */
        $attempt = PaymentGatewayAttempt::query()
            ->where('provider', $webhook->provider)
            ->where('provider_attempt_id', $webhook->provider_attempt_id)
            ->first();

        if ($attempt === null) {
            throw PaymentGatewayException::webhookProcessingFailure(
                'The gateway webhook attempt could not be resolved.',
            );
        }

        $expectedEnvironment = (string) config('payment_gateways.environment', 'sandbox');
        $currency = strtoupper($webhook->currency);

        if (
            $attempt->environment !== $expectedEnvironment
            || ($webhook->environment !== null && $webhook->environment !== $attempt->environment)
            || $attempt->amount_cents !== $webhook->amount_cents
            || strtoupper($attempt->currency) !== $currency
        ) {
            throw PaymentGatewayException::webhookProcessingFailure(
                'The gateway webhook does not match the recorded attempt.',
            );
        }

        /** @var Payment $payment */
        $payment = Payment::query()->whereKey($attempt->payment_id)->firstOrFail();

        /** @var Order $order */
        $order = Order::query()->whereKey($attempt->order_id)->firstOrFail();

        if ($payment->order_id !== $order->id) {
            throw PaymentGatewayException::webhookProcessingFailure(
                'The gateway webhook relationships are inconsistent.',
            );
        }

        $transaction = $this->recordTransaction->execute(new PaymentGatewayTransactionData(
            paymentGatewayAttemptId: $attempt->id,
            paymentId: $payment->id,
            provider: $webhook->provider,
            providerTransactionId: $webhook->provider_transaction_id,
            status: $status->value,
            amountCents: $webhook->amount_cents,
            currency: $currency,
            authorizedAt: in_array($status, [
                PaymentGatewayTransactionStatus::Authorized,
                PaymentGatewayTransactionStatus::Paid,
                PaymentGatewayTransactionStatus::Captured,
            ], true) ? $webhook->occurred_at->toImmutable() : null,
            capturedAt: in_array($status, [
                PaymentGatewayTransactionStatus::Paid,
                PaymentGatewayTransactionStatus::Captured,
            ], true) ? $webhook->occurred_at->toImmutable() : null,
            failedAt: in_array($status, [
                PaymentGatewayTransactionStatus::Failed,
                PaymentGatewayTransactionStatus::Expired,
            ], true) ? $webhook->occurred_at->toImmutable() : null,
        ));

        $wasSettlementApplied = false;

        if (in_array($status, [
            PaymentGatewayTransactionStatus::Paid,
            PaymentGatewayTransactionStatus::Captured,
        ], true)) {
            $settlementResult = $this->settlement->executeWithinTransaction(
                new GatewayPaymentSettlementRequest(
                    transactionId: $transaction->id,
                    provider: $webhook->provider,
                ),
            );
            $wasSettlementApplied = $settlementResult->wasSettlementApplied;
        }

        return new ProcessGatewayWebhookResult(
            webhookId: $webhook->id,
            provider: $webhook->provider,
            providerEventId: $webhook->provider_event_id,
            status: $status,
            transactionId: $transaction->id,
            wasAlreadyProcessed: false,
            wasSettlementApplied: $wasSettlementApplied,
            processedAt: null,
        );
    }

    private function markProcessed(
        string $webhookId,
        ProcessGatewayWebhookResult $result,
    ): ProcessGatewayWebhookResult {
        return DB::transaction(function () use ($webhookId, $result): ProcessGatewayWebhookResult {
            /** @var PaymentGatewayWebhook $webhook */
            $webhook = PaymentGatewayWebhook::query()
                ->whereKey($webhookId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($webhook->processed_at !== null) {
                return new ProcessGatewayWebhookResult(
                    webhookId: $result->webhookId,
                    provider: $result->provider,
                    providerEventId: $result->providerEventId,
                    status: $result->status,
                    transactionId: $result->transactionId,
                    wasAlreadyProcessed: true,
                    wasSettlementApplied: false,
                    processedAt: $webhook->processed_at->toImmutable(),
                );
            }

            $processedAt = now();
            $webhook->processed_at = $processedAt;
            $webhook->failed_at = null;
            $webhook->last_error = null;
            $webhook->save();

            return new ProcessGatewayWebhookResult(
                webhookId: $result->webhookId,
                provider: $result->provider,
                providerEventId: $result->providerEventId,
                status: $result->status,
                transactionId: $result->transactionId,
                wasAlreadyProcessed: false,
                wasSettlementApplied: $result->wasSettlementApplied,
                processedAt: $processedAt->toImmutable(),
            );
        });
    }

    private function resultFromWebhook(
        PaymentGatewayWebhook $webhook,
        bool $wasAlreadyProcessed,
    ): ProcessGatewayWebhookResult {
        $status = PaymentGatewayTransactionStatus::tryFrom((string) $webhook->normalized_status);

        if ($status === null) {
            throw PaymentGatewayException::webhookProcessingFailure(
                'The processed gateway webhook has an invalid stored status.',
            );
        }

        $transactionId = $webhook->provider_transaction_id === null
            ? null
            : PaymentGatewayTransaction::query()
                ->where('provider', $webhook->provider)
                ->where('provider_transaction_id', $webhook->provider_transaction_id)
                ->value('id');

        return new ProcessGatewayWebhookResult(
            webhookId: $webhook->id,
            provider: $webhook->provider,
            providerEventId: $webhook->provider_event_id,
            status: $status,
            transactionId: $transactionId,
            wasAlreadyProcessed: $wasAlreadyProcessed,
            wasSettlementApplied: false,
            processedAt: $webhook->processed_at?->toImmutable(),
        );
    }

    private function markFailedSafely(string $webhookId, Throwable $exception): void
    {
        try {
            DB::transaction(function () use ($webhookId, $exception): void {
                /** @var PaymentGatewayWebhook|null $webhook */
                $webhook = PaymentGatewayWebhook::query()
                    ->whereKey($webhookId)
                    ->lockForUpdate()
                    ->first();

                if ($webhook === null || $webhook->processed_at !== null) {
                    return;
                }

                $webhook->failed_at = now();
                $webhook->processing_attempts = max((int) $webhook->processing_attempts, 1);
                $webhook->last_error = mb_substr($this->safeError($exception), 0, 1000);
                $webhook->save();
            });
        } catch (Throwable) {
            // The original processing error remains the actionable failure.
        }
    }

    private function safeError(Throwable $exception): string
    {
        if ($exception instanceof PaymentGatewayException) {
            return $exception->getMessage();
        }

        return 'Unexpected gateway webhook processing failure.';
    }
}
