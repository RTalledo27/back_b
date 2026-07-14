<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

final readonly class PaymentGatewayTransactionData
{
    public function __construct(
        public string $paymentGatewayAttemptId,
        public string $paymentId,
        string $provider,
        string $providerTransactionId,
        string $status,
        public int $amountCents,
        string $currency,
        public ?CarbonImmutable $authorizedAt = null,
        public ?CarbonImmutable $capturedAt = null,
        public ?CarbonImmutable $failedAt = null,
        public ?string $rawReferenceHash = null,
    ) {
        $this->provider = self::required($provider, 'provider');
        $this->providerTransactionId = self::required($providerTransactionId, 'providerTransactionId');
        $this->status = self::required($status, 'status');
        $this->currency = self::required($currency, 'currency');

        if ($this->amountCents <= 0) {
            throw PaymentGatewayException::invalidInput('amountCents must be greater than zero.');
        }

        if (strlen($this->currency) !== 3) {
            throw PaymentGatewayException::invalidInput('currency must be an ISO 4217 code.');
        }
    }

    public readonly string $provider;

    public readonly string $providerTransactionId;

    public readonly string $status;

    public readonly string $currency;

    private static function required(string $value, string $field): string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw PaymentGatewayException::invalidInput("{$field} must not be empty.");
        }

        return $field === 'currency' ? strtoupper($normalized) : $normalized;
    }
}
