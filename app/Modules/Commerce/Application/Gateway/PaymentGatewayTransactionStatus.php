<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

enum PaymentGatewayTransactionStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Captured = 'captured';
    case Failed = 'failed';
    case Expired = 'expired';
    case Refunded = 'refunded';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Captured, self::Failed, self::Expired, self::Refunded], true);
    }
}
