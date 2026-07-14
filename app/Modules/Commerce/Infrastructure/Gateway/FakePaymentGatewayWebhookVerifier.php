<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Gateway;

use App\Modules\Commerce\Application\Gateway\PaymentGatewayWebhookVerifier;
use Carbon\CarbonImmutable;

final class FakePaymentGatewayWebhookVerifier implements PaymentGatewayWebhookVerifier
{
    public function verify(
        string $rawPayload,
        string $signature,
        string $secret,
        CarbonImmutable $now,
        int $toleranceSeconds,
    ): bool {
        if ($secret === '' || $toleranceSeconds < 0) {
            return false;
        }

        if (preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', trim($signature), $matches) !== 1) {
            return false;
        }

        $timestamp = (int) $matches[1];
        if (abs($now->timestamp - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawPayload, $secret);

        return hash_equals($expected, $matches[2]);
    }

    public function sign(string $rawPayload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$rawPayload, $secret);
    }
}
