<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

interface PaymentGatewayWebhookNormalizer
{
    /**
     * @param  array<string, string>  $headers
     */
    public function normalize(
        string $provider,
        string $rawPayload,
        array $headers = [],
    ): PaymentGatewayWebhookPayload;
}
