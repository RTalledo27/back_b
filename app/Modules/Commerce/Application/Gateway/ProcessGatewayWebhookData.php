<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

final readonly class ProcessGatewayWebhookData
{
    public function __construct(public string $webhookId)
    {
        if (trim($this->webhookId) === '') {
            throw PaymentGatewayException::invalidInput('webhookId must not be empty.');
        }
    }
}
