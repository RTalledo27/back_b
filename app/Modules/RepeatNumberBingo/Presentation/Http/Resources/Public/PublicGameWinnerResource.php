<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Public;

use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read GameWinner $resource
 */
final class PublicGameWinnerResource extends JsonResource
{
    /**
     * @return array{number: ?int, draw_sequence: ?int, hits: int, won_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'number' => $this->resource->gameNumber?->number,
            'draw_sequence' => $this->resource->draw?->sequence,
            'hits' => $this->resource->winning_hits,
            'won_at' => $this->resource->won_at->utc()->toIso8601String(),
        ];
    }
}
