<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumberCounter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListMyEntriesQuery
{
    /**
     * @return LengthAwarePaginator<int, GameEntry>
     */
    public function paginate(int $userId, ?string $gameId, int $perPage = 20): LengthAwarePaginator
    {
        $query = GameEntry::query()
            ->select('game_entries.*')
            ->selectSub(
                GameNumberCounter::query()
                    ->select('hits_count')
                    ->whereColumn('game_number_counters.game_number_id', 'game_entries.game_number_id')
                    ->limit(1),
                'live_hits_current',
            )
            ->with([
                'game:id,slug,name,hits_required,status,completed_at',
                'game.latestDraw:id,game_id,sequence,drawn_number,drawn_at',
                'game.winner:id,game_id,game_entry_id,winning_hits,won_at',
                'gameNumber:id,game_id,number,status',
            ])
            ->where('user_id', $userId);

        if ($gameId !== null) {
            $query->where('game_id', $gameId);
        }

        return $query->orderByDesc('confirmed_at')->paginate($perPage);
    }
}
