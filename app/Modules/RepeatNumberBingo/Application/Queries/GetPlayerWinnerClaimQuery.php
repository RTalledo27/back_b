<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;

final class GetPlayerWinnerClaimQuery
{
    public function findForUser(string $winnerId, int $userId): ?WinnerClaim
    {
        return WinnerClaim::query()
            ->with([
                'gameWinner.game:id,slug,name,prize_cents,currency',
                'gameWinner.gameNumber:id,number',
                'gameWinner.draw:id,sequence',
            ])
            ->where('game_winner_id', $winnerId)
            ->where('winner_user_id', $userId)
            ->first();
    }
}
