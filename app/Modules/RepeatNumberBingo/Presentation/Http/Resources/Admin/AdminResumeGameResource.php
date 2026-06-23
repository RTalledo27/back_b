<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Application\DTOs\ResumeGameResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ResumeGameResult $resource
 */
final class AdminResumeGameResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'game_id' => $this->resource->gameId,
            'status' => 'running',
            'outcome' => $this->resource->outcome->value,
            'resumed_at' => $this->resource->resumedAt->toIso8601String(),
            'next_draw_at' => $this->resource->nextDrawAt->toIso8601String(),
        ];
    }
}
