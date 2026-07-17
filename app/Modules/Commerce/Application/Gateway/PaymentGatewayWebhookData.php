<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

final readonly class PaymentGatewayWebhookData
{
    public function __construct(
        string $provider,
        string $providerEventId,
        string $eventType,
        public bool $signatureVerified,
        string $payloadHash,
        public ?PaymentGatewayTransactionStatus $normalizedStatus = null,
        public ?int $amountCents = null,
        ?string $currency = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $providerAttemptId = null,
        public ?string $providerTransactionId = null,
        public ?string $environment = null,
    ) {
        $this->provider = self::required($provider, 'provider');
        $this->providerEventId = self::required($providerEventId, 'providerEventId');
        $this->eventType = self::required($eventType, 'eventType');
        $this->payloadHash = self::required($payloadHash, 'payloadHash');
        $this->currency = $currency === null ? null : strtoupper(self::required($currency, 'currency'));

        foreach ([
            'providerAttemptId' => $this->providerAttemptId,
            'providerTransactionId' => $this->providerTransactionId,
            'environment' => $this->environment,
        ] as $field => $value) {
            if ($value !== null && trim($value) === '') {
                throw PaymentGatewayException::invalidInput("{$field} must not be empty.");
            }
        }
    }

    public readonly string $provider;

    public readonly string $providerEventId;

    public readonly string $eventType;

    public readonly string $payloadHash;

    public readonly ?string $currency;

    private static function required(string $value, string $field): string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw PaymentGatewayException::invalidInput("{$field} must not be empty.");
        }

        return $normalized;
    }
}
