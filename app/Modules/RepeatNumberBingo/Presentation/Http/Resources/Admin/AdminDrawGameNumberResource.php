<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Application\DTOs\DrawGameNumberResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read DrawGameNumberResult $resource
 */
final class AdminDrawGameNumberResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'game_id' => $this->resource->gameId,
            'draw_id' => $this->resource->drawId,
            'game_number_id' => $this->resource->gameNumberId,
            'sequence' => $this->resource->sequence,
            'drawn_number' => $this->resource->drawnNumber,
            'current_hits' => $this->resource->currentHits,
            'hits_required' => $this->resource->hitsRequired,
            'number_is_sold' => $this->resource->numberIsSold,
            'winner_created' => $this->resource->winnerCreated,
            'winner_entry_id' => $this->resource->winnerEntryId,
            'game_status' => $this->resource->gameStatus,
            'drawn_at' => $this->resource->drawnAt->toIso8601String(),
            'replay' => $this->resource->wasReplay,
        ];
    }
}
