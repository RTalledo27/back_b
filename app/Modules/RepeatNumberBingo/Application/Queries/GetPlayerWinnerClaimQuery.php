<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;

final class GetPlayerWinnerClaimQuery
{
    public function __construct(private readonly PlayerWinnerSettlementSnapshotQuery $settlementSnapshot) {}

    public function findForUser(string $winnerId, int $userId): ?WinnerClaim
    {
        $claim = WinnerClaim::query()
            ->with([
                'gameWinner.game:id,slug,name,prize_cents,currency',
                'gameWinner.gameNumber:id,number',
                'gameWinner.draw:id,sequence',
            ])
            ->where('game_winner_id', $winnerId)
            ->where('winner_user_id', $userId)
            ->first();

        if ($claim !== null) {
            $claim->setAttribute('settlement', $this->settlementSnapshot->forWinnerIds([(string) $claim->game_winner_id])[(string) $claim->game_winner_id] ?? []);
        }

        return $claim;
    }
}
