<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read GameDraw $resource
 */
final class AdminGameDrawResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'game_id' => $this->resource->game_id,
            'game_number_id' => $this->resource->game_number_id,
            'sequence' => $this->resource->sequence,
            'drawn_number' => $this->resource->drawn_number,
            'strategy' => $this->resource->strategy,
            'drawn_at' => $this->resource->drawn_at->toIso8601String(),
        ];
    }
}
