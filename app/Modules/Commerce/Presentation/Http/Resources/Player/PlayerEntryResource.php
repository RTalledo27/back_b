<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Player;

use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GameEntry
 */
final class PlayerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'game_id' => $this->game_id,
            'game_number_id' => $this->game_number_id,
            'status' => $this->status->value,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'game' => $this->whenLoaded('game', fn (): ?array => $this->game === null ? null : [
                'id' => $this->game->id,
                'slug' => $this->game->slug,
                'name' => $this->game->name,
            ]),
            'game_number' => $this->whenLoaded('gameNumber', fn (): ?array => $this->gameNumber === null ? null : [
                'id' => $this->gameNumber->id,
                'number' => (int) $this->gameNumber->number,
                'status' => $this->gameNumber->status->value,
            ]),
        ];
    }
}
