<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

final readonly class GatewayPaymentConfirmationRequest
{
    public function __construct(
        public string $attemptId,
        public string $idempotencyKeyHash,
        public string $requestFingerprint,
    ) {
        foreach ([
            'attemptId' => $this->attemptId,
            'idempotencyKeyHash' => $this->idempotencyKeyHash,
            'requestFingerprint' => $this->requestFingerprint,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw PaymentGatewayException::invalidInput("{$field} must not be empty.");
            }
        }
    }
}
