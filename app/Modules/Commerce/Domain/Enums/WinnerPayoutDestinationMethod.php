<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum WinnerPayoutDestinationMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Yape = 'yape';
    case Plin = 'plin';
    case Cash = 'cash';
    case Other = 'other';
}
