<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Public;

use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read GameDraw $resource
 */
final class PublicGameDrawResource extends JsonResource
{
    /**
     * @return array<string, int|string>
     */
    public function toArray(Request $request): array
    {
        return [
            'sequence' => $this->resource->sequence,
            'number' => $this->resource->drawn_number,
            'drawn_at' => $this->resource->drawn_at->utc()->toIso8601String(),
        ];
    }
}
