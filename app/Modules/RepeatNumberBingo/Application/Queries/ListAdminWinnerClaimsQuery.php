<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminWinnerClaimsQuery
{
    public function paginate(?string $status = null, ?string $gameId = null, int $perPage = 25): LengthAwarePaginator
    {
        return WinnerClaim::query()
            ->with([
                'gameWinner.game:id,slug,name',
                'winner:id,name,email',
            ])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($gameId !== null, fn ($query) => $query->whereHas(
                'gameWinner',
                fn ($winnerQuery) => $winnerQuery->where('game_id', $gameId),
            ))
            ->latest('created_at')
            ->paginate(min(max($perPage, 1), 100));
    }
}
