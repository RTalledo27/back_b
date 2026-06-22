<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Application\DTOs\StartGameResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read StartGameResult $resource
 */
final class AdminStartGameResource extends JsonResource
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
            'scheduled_start_at' => $this->resource->scheduledStartAt->toIso8601String(),
            'started_at' => $this->resource->startedAt->toIso8601String(),
            'confirmed_entries_count' => $this->resource->confirmedEntriesCount,
        ];
    }
}
