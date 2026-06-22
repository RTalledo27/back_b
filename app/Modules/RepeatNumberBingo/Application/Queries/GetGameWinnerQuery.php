<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;

final class GetGameWinnerQuery
{
    public function findForGame(string $gameId): ?GameWinner
    {
        return GameWinner::query()
            ->with(['gameNumber:id,number', 'draw:id,sequence'])
            ->where('game_id', $gameId)
            ->first();
    }
}
