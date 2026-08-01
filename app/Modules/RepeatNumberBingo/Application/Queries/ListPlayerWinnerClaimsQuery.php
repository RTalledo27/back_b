<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListPlayerWinnerClaimsQuery
{
    public function __construct(private readonly PlayerWinnerSettlementSnapshotQuery $settlementSnapshot) {}

    public function paginate(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        $paginator = WinnerClaim::query()
            ->with([
                'gameWinner.game:id,slug,name,prize_cents,currency',
                'gameWinner.gameNumber:id,number',
                'gameWinner.draw:id,sequence',
            ])
            ->where('winner_user_id', $userId)
            ->latest('created_at')
            ->paginate(min(max($perPage, 1), 50));

        $winnerIds = collect($paginator->items())->map(fn (WinnerClaim $claim): string => (string) $claim->game_winner_id)->values()->all();
        $snapshots = $this->settlementSnapshot->forWinnerIds($winnerIds);
        foreach ($paginator->items() as $claim) {
            $claim->setAttribute('settlement', $snapshots[(string) $claim->game_winner_id] ?? []);
        }

        return $paginator;
    }
}
