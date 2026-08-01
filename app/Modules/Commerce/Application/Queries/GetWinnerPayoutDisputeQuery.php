<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;

final class GetWinnerPayoutDisputeQuery
{
    public function execute(string $disputeId): WinnerPayoutDispute
    {
        return WinnerPayoutDispute::query()
            ->with(['payout', 'winner', 'openedBy', 'reviewedBy', 'resolvedBy'])
            ->whereKey($disputeId)
            ->firstOrFail();
    }
}
