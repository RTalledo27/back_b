<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

final readonly class PaymentGatewayAttemptData
{
    public function __construct(
        public string $orderId,
        public string $paymentId,
        public int $amountCents,
        string $currency,
        string $provider,
        string $environment,
        string $idempotencyKeyHash,
        string $requestFingerprint,
        string $status = 'pending',
        public ?string $providerAttemptId = null,
        public ?string $checkoutUrl = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {
        if ($this->amountCents <= 0) {
            throw PaymentGatewayException::invalidInput('amountCents must be greater than zero.');
        }

        $this->currency = self::required($currency, 'currency');
        if (strlen($this->currency) !== 3) {
            throw PaymentGatewayException::invalidInput('currency must be an ISO 4217 code.');
        }

        $this->provider = self::required($provider, 'provider');
        $this->environment = self::required($environment, 'environment');
        $this->idempotencyKeyHash = self::required($idempotencyKeyHash, 'idempotencyKeyHash');
        $this->requestFingerprint = self::required($requestFingerprint, 'requestFingerprint');
        $this->status = self::required($status, 'status');
    }

    public readonly string $currency;

    public readonly string $provider;

    public readonly string $environment;

    public readonly string $idempotencyKeyHash;

    public readonly string $requestFingerprint;

    public readonly string $status;

    private static function required(string $value, string $field): string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw PaymentGatewayException::invalidInput("{$field} must not be empty.");
        }

        return $field === 'currency' ? strtoupper($normalized) : $normalized;
    }
}
