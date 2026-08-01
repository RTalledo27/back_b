<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListWinnerPayoutDisputesQuery
{
    /** @param array<string, mixed> $filters */
    public function execute(array $filters = []): LengthAwarePaginator
    {
        return WinnerPayoutDispute::query()
            ->with(['payout', 'winner'])
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->latest('created_at')
            ->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));
    }
}
