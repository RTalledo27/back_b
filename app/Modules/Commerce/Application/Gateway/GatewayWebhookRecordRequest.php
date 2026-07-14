<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

final readonly class GatewayWebhookRecordRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $provider,
        public string $rawPayload,
        public string $signature,
        public array $headers = [],
        public ?CarbonImmutable $now = null,
    ) {
        foreach ([
            'provider' => $this->provider,
            'rawPayload' => $this->rawPayload,
            'signature' => $this->signature,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw PaymentGatewayException::invalidInput("{$field} must not be empty.");
            }
        }
    }
}
