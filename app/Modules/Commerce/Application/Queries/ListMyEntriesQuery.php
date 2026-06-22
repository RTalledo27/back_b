<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListMyEntriesQuery
{
    /**
     * @return LengthAwarePaginator<int, GameEntry>
     */
    public function paginate(int $userId, ?string $gameId, int $perPage = 20): LengthAwarePaginator
    {
        $query = GameEntry::query()
            ->with([
                'game:id,slug,name',
                'gameNumber:id,game_id,number,status',
            ])
            ->where('user_id', $userId);

        if ($gameId !== null) {
            $query->where('game_id', $gameId);
        }

        return $query->orderByDesc('confirmed_at')->paginate($perPage);
    }
}
