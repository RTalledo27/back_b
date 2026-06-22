<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read RebuildCountersResult $resource
 */
final class AdminRebuildCountersResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'game_id' => $this->resource->gameId,
            'outcome' => $this->resource->outcome->value,
            'previous_rows' => $this->resource->previousRows,
            'previous_hits_total' => $this->resource->previousHitsTotal,
            'rebuilt_rows' => $this->resource->rebuiltRows,
            'rebuilt_hits_total' => $this->resource->rebuiltHitsTotal,
            'total_draws' => $this->resource->totalDraws,
            'max_sequence' => $this->resource->maxSequence,
            'rebuilt_at' => $this->resource->rebuiltAt->toIso8601String(),
        ];
    }
}
