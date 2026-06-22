<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListGameDrawsQuery
{
    /**
     * @param  array{number?: int, sequence_from?: int, sequence_to?: int, drawn_from?: string, drawn_to?: string}  $filters
     * @return LengthAwarePaginator<int, GameDraw>
     */
    public function paginate(string $gameId, array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $perPage = max(1, min(100, $perPage));

        $query = GameDraw::query()->where('game_id', $gameId);

        if (isset($filters['number'])) {
            $query->where('drawn_number', (int) $filters['number']);
        }
        if (isset($filters['sequence_from'])) {
            $query->where('sequence', '>=', (int) $filters['sequence_from']);
        }
        if (isset($filters['sequence_to'])) {
            $query->where('sequence', '<=', (int) $filters['sequence_to']);
        }
        if (isset($filters['drawn_from'])) {
            $query->where('drawn_at', '>=', $filters['drawn_from']);
        }
        if (isset($filters['drawn_to'])) {
            $query->where('drawn_at', '<=', $filters['drawn_to']);
        }

        return $query->orderBy('sequence')->paginate($perPage);
    }
}
