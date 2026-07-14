<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

final readonly class PaymentGatewayCreateAttemptData
{
    public function __construct(
        public string $orderId,
        public string $paymentId,
        public int $amountCents,
        string $currency,
        string $idempotencyKeyHash,
        string $requestFingerprint,
        public ?CarbonImmutable $expiresAt = null,
    ) {
        if ($this->amountCents <= 0) {
            throw PaymentGatewayException::invalidInput('amountCents must be greater than zero.');
        }

        $normalizedCurrency = strtoupper(trim($currency));
        if (strlen($normalizedCurrency) !== 3) {
            throw PaymentGatewayException::invalidInput('currency must be an ISO 4217 code.');
        }

        $normalizedKeyHash = trim($idempotencyKeyHash);
        if ($normalizedKeyHash === '') {
            throw PaymentGatewayException::invalidInput('idempotencyKeyHash must not be empty.');
        }

        $normalizedFingerprint = trim($requestFingerprint);
        if ($normalizedFingerprint === '') {
            throw PaymentGatewayException::invalidInput('requestFingerprint must not be empty.');
        }

        $this->currency = $normalizedCurrency;
        $this->idempotencyKeyHash = $normalizedKeyHash;
        $this->requestFingerprint = $normalizedFingerprint;
    }

    public readonly string $currency;

    public readonly string $idempotencyKeyHash;

    public readonly string $requestFingerprint;
}
