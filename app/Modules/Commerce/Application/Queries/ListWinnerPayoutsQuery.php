<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\Commerce\Domain\Models\WinnerPayout;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListWinnerPayoutsQuery
{
    /** @param array<string, mixed> $filters */
    public function execute(array $filters = []): LengthAwarePaginator
    {
        return WinnerPayout::query()
            ->with(['currentDestination', 'currentExecutionAttempt.destination', 'executionAttempts.destination', 'documents', 'game'])
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['game_id']), fn ($query) => $query->where('game_id', $filters['game_id']))
            ->when(isset($filters['from']), fn ($query) => $query->where('created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->where('created_at', '<=', $filters['to']))
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }
}
