<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

final readonly class GatewayPaymentSettlementRequest
{
    public function __construct(
        public string $transactionId,
        public string $provider,
    ) {
        foreach (['transactionId' => $this->transactionId, 'provider' => $this->provider] as $field => $value) {
            if (trim($value) === '') {
                throw PaymentGatewayException::invalidInput("{$field} must not be empty.");
            }
        }
    }
}
