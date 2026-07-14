<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Gateway;

use App\Modules\Commerce\Application\Gateway\PaymentGatewayConfirmData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayConfirmResult;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayCreateAttemptData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayCreateAttemptResult;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayProvider;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayTransactionStatus;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayWebhookPayload;
use Carbon\CarbonImmutable;

final class FakePaymentGatewayProvider implements PaymentGatewayProvider
{
    /** @var array<string, PaymentGatewayCreateAttemptResult> */
    private array $attempts = [];

    /** @var array<string, string> */
    private array $attemptFingerprints = [];

    /** @var array<string, PaymentGatewayConfirmResult> */
    private array $confirmations = [];

    /** @var array<string, string> */
    private array $confirmationFingerprints = [];

    /** @var array<string, true> */
    private array $processedWebhookKeys = [];

    private PaymentGatewayTransactionStatus $createAttemptStatus = PaymentGatewayTransactionStatus::Pending;

    private PaymentGatewayTransactionStatus $confirmStatus = PaymentGatewayTransactionStatus::Paid;

    private ?PaymentGatewayException $createFailure = null;

    public function name(): string
    {
        return 'fake';
    }

    public function createAttempt(PaymentGatewayCreateAttemptData $data): PaymentGatewayCreateAttemptResult
    {
        $requestKey = implode('|', [$data->orderId, $data->paymentId, $data->idempotencyKeyHash]);
        $existing = $this->attempts[$requestKey] ?? null;

        if ($existing !== null) {
            $expectedFingerprint = $this->attemptFingerprints[$requestKey];

            if (! hash_equals($expectedFingerprint, $data->requestFingerprint)) {
                throw PaymentGatewayException::idempotencyConflict();
            }

            return $existing;
        }

        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }

        $providerAttemptId = 'fake-attempt-'.substr(
            hash('sha256', $requestKey.'|'.$data->requestFingerprint),
            0,
            32,
        );
        $createdAt = CarbonImmutable::now('UTC');
        $result = new PaymentGatewayCreateAttemptResult(
            provider: $this->name(),
            providerAttemptId: $providerAttemptId,
            status: $this->createAttemptStatus,
            amountCents: $data->amountCents,
            currency: $data->currency,
            checkoutUrl: $this->createAttemptStatus->isTerminal()
                ? null
                : 'fake://checkout/'.$providerAttemptId,
            expiresAt: $data->expiresAt,
            createdAt: $createdAt,
        );

        $this->attempts[$requestKey] = $result;
        $this->attemptFingerprints[$requestKey] = $data->requestFingerprint;

        return $result;
    }

    public function confirm(PaymentGatewayConfirmData $data): PaymentGatewayConfirmResult
    {
        $attempt = $this->findAttempt($data->providerAttemptId);
        $requestKey = $data->providerAttemptId.'|'.$data->idempotencyKeyHash;
        $existing = $this->confirmations[$requestKey] ?? null;

        if ($existing !== null) {
            $expectedFingerprint = $this->confirmationFingerprints[$requestKey];

            if (! hash_equals($expectedFingerprint, $data->requestFingerprint)) {
                throw PaymentGatewayException::idempotencyConflict();
            }

            return $existing;
        }

        $processedAt = CarbonImmutable::now('UTC');
        $authorizedAt = in_array(
            $this->confirmStatus,
            [PaymentGatewayTransactionStatus::Authorized, PaymentGatewayTransactionStatus::Paid],
            true,
        ) ? $processedAt : null;
        $capturedAt = $this->confirmStatus === PaymentGatewayTransactionStatus::Paid
            ? $processedAt
            : null;
        $failedAt = in_array(
            $this->confirmStatus,
            [PaymentGatewayTransactionStatus::Failed, PaymentGatewayTransactionStatus::Expired],
            true,
        ) ? $processedAt : null;

        $result = new PaymentGatewayConfirmResult(
            provider: $this->name(),
            providerAttemptId: $attempt->providerAttemptId,
            providerTransactionId: 'fake-transaction-'.substr(
                hash('sha256', $requestKey.'|'.$data->requestFingerprint),
                0,
                32,
            ),
            status: $this->confirmStatus,
            amountCents: $attempt->amountCents,
            currency: $attempt->currency,
            authorizedAt: $authorizedAt,
            capturedAt: $capturedAt,
            failedAt: $failedAt,
            processedAt: $processedAt,
        );

        $this->confirmations[$requestKey] = $result;
        $this->confirmationFingerprints[$requestKey] = $data->requestFingerprint;

        return $result;
    }

    public function setCreateAttemptStatus(PaymentGatewayTransactionStatus $status): void
    {
        $this->createAttemptStatus = $status;
    }

    public function setConfirmStatus(PaymentGatewayTransactionStatus $status): void
    {
        $this->confirmStatus = $status;
    }

    public function failCreateAttempt(string $message = 'Fake provider create attempt failure.'): void
    {
        $this->createFailure = PaymentGatewayException::providerFailure($message);
    }

    public function clearCreateAttemptFailure(): void
    {
        $this->createFailure = null;
    }

    public function acceptWebhook(PaymentGatewayWebhookPayload $payload): bool
    {
        if (! $payload->signatureVerified) {
            return false;
        }

        $key = $payload->provider.'|'.$payload->providerEventId;

        if (isset($this->processedWebhookKeys[$key])) {
            return false;
        }

        $this->processedWebhookKeys[$key] = true;

        return true;
    }

    public function processedWebhookCount(): int
    {
        return count($this->processedWebhookKeys);
    }

    private function findAttempt(string $providerAttemptId): PaymentGatewayCreateAttemptResult
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->providerAttemptId === $providerAttemptId) {
                return $attempt;
            }
        }

        throw PaymentGatewayException::attemptNotFound($providerAttemptId);
    }
}
