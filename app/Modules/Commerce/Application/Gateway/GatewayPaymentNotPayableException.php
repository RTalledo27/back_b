<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use RuntimeException;

final class GatewayPaymentNotPayableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The order and payment are not payable through the gateway.', 1007);
    }
}
