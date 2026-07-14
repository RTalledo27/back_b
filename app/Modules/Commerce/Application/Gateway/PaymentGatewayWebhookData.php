<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

final readonly class PaymentGatewayWebhookData
{
    public function __construct(
        string $provider,
        string $providerEventId,
        string $eventType,
        public bool $signatureVerified,
        string $payloadHash,
    ) {
        $this->provider = self::required($provider, 'provider');
        $this->providerEventId = self::required($providerEventId, 'providerEventId');
        $this->eventType = self::required($eventType, 'eventType');
        $this->payloadHash = self::required($payloadHash, 'payloadHash');
    }

    public readonly string $provider;

    public readonly string $providerEventId;

    public readonly string $eventType;

    public readonly string $payloadHash;

    private static function required(string $value, string $field): string
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw PaymentGatewayException::invalidInput("{$field} must not be empty.");
        }

        return $normalized;
    }
}
