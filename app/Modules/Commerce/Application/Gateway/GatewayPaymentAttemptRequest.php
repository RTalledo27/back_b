<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

final readonly class GatewayPaymentAttemptRequest
{
    public function __construct(
        public int $userId,
        public string $orderId,
        public string $paymentId,
        public string $provider,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
        public ?CarbonImmutable $expiresAt = null,
    ) {
        if ($this->userId <= 0) {
            throw PaymentGatewayException::invalidInput('userId must be greater than zero.');
        }

        self::required($this->orderId, 'orderId');
        self::required($this->paymentId, 'paymentId');
        self::required($this->provider, 'provider');
        self::required($this->idempotencyKeyHash, 'idempotencyKeyHash');
        self::required($this->requestFingerprint, 'requestFingerprint');
    }

    private static function required(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw PaymentGatewayException::invalidInput("{$field} must not be empty.");
        }
    }
}
