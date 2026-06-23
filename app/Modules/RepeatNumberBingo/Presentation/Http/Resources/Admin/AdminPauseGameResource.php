<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Application\DTOs\PauseGameResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PauseGameResult $resource
 */
final class AdminPauseGameResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'game_id' => $this->resource->gameId,
            'status' => 'paused',
            'outcome' => $this->resource->outcome->value,
            'paused_at' => $this->resource->pausedAt->toIso8601String(),
        ];
    }
}
