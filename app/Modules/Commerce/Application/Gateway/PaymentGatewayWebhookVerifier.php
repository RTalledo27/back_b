<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use Carbon\CarbonImmutable;

interface PaymentGatewayWebhookVerifier
{
    public function verify(
        string $rawPayload,
        string $signature,
        string $secret,
        CarbonImmutable $now,
        int $toleranceSeconds,
    ): bool;
}
