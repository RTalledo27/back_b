<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use RuntimeException;

final class GatewayWebhookSignatureException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The gateway webhook signature is invalid.', 1008);
    }
}
