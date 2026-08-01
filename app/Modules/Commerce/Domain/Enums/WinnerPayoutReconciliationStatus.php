<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum WinnerPayoutReconciliationStatus: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case Discrepancy = 'discrepancy';
    case Corrected = 'corrected';
}
