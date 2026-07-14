<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

final readonly class PaymentGatewayConfirmData
{
    public function __construct(
        public string $providerAttemptId,
        string $idempotencyKeyHash,
        string $requestFingerprint,
    ) {
        $normalizedKeyHash = trim($idempotencyKeyHash);
        $normalizedFingerprint = trim($requestFingerprint);

        if ($normalizedKeyHash === '' || $normalizedFingerprint === '') {
            throw PaymentGatewayException::invalidInput(
                'idempotencyKeyHash and requestFingerprint must not be empty.',
            );
        }

        $this->idempotencyKeyHash = $normalizedKeyHash;
        $this->requestFingerprint = $normalizedFingerprint;
    }

    public readonly string $idempotencyKeyHash;

    public readonly string $requestFingerprint;
}
