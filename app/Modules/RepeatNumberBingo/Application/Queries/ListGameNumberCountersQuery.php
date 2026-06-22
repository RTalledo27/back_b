<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Lists all materialised game_numbers of a game LEFT-JOINed against
 * game_number_counters. A number not yet drawn yields hits_count=0 and
 * last_draw_sequence=null. Paginated because BingoNumberRange has no
 * hard cap and counters must not be allowed to flood the response.
 */
final class ListGameNumberCountersQuery
{
    /**
     * @param  array{number_from?: int, number_to?: int, min_hits?: int, max_hits?: int, status?: GameNumberStatus|string}  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function paginate(string $gameId, array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));

        $query = DB::table('game_numbers AS gn')
            ->leftJoin('game_number_counters AS gnc', function ($join): void {
                $join->on('gnc.game_number_id', '=', 'gn.id')
                    ->on('gnc.game_id', '=', 'gn.game_id');
            })
            ->where('gn.game_id', $gameId)
            ->select([
                'gn.id AS game_number_id',
                'gn.number AS number',
                'gn.status AS status',
                DB::raw('COALESCE(gnc.hits_count, 0) AS hits_count'),
                'gnc.last_draw_sequence AS last_draw_sequence',
            ]);

        if (isset($filters['number_from'])) {
            $query->where('gn.number', '>=', (int) $filters['number_from']);
        }
        if (isset($filters['number_to'])) {
            $query->where('gn.number', '<=', (int) $filters['number_to']);
        }
        if (isset($filters['min_hits'])) {
            $query->whereRaw('COALESCE(gnc.hits_count, 0) >= ?', [(int) $filters['min_hits']]);
        }
        if (isset($filters['max_hits'])) {
            $query->whereRaw('COALESCE(gnc.hits_count, 0) <= ?', [(int) $filters['max_hits']]);
        }
        if (isset($filters['status'])) {
            $value = $filters['status'] instanceof GameNumberStatus
                ? $filters['status']->value
                : (string) $filters['status'];
            $query->where('gn.status', $value);
        }

        return $query->orderBy('gn.number')->paginate($perPage);
    }
}
