<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;

final class GetGamePrizeFundingQuery
{
    public function execute(string $gameId): GamePrizeFunding
    {
        /** @var GamePrizeFunding $funding */
        $funding = GamePrizeFunding::query()
            ->where('game_id', $gameId)
            ->with(['documents' => fn ($query) => $query->latest('created_at')])
            ->firstOrFail();

        return $funding;
    }
}
