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
            'live_progress' => $this->when(
                $this->resource->relationLoaded('game') && $this->game !== null,
                fn (): array => $this->liveProgressPayload(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function liveProgressPayload(): array
    {
        $hitsCurrent = (int) ($this->resource->getAttribute('live_hits_current') ?? 0);
        $latestDraw = $this->game?->relationLoaded('latestDraw') ? $this->game->latestDraw : null;
        $winner = $this->game?->relationLoaded('winner') ? $this->game->winner : null;
        $isWinner = $this->status->value === 'winner' || $winner?->game_entry_id === $this->id;

        return [
            'entry_id' => $this->id,
            'game_id' => $this->game_id,
            'game_status' => $this->game?->status->value,
            'game_number' => $this->gameNumber === null ? null : (int) $this->gameNumber->number,
            'hits_current' => $hitsCurrent,
            'hits_required' => $this->game?->hits_required,
            'latest_draw_number' => $latestDraw?->drawn_number,
            'latest_draw_sequence' => $latestDraw?->sequence,
            'is_winner' => $isWinner,
            'completed_at' => $this->game?->completed_at?->utc()->toIso8601String(),
            'won_at' => $isWinner ? $winner?->won_at?->utc()->toIso8601String() : null,
        ];
    }
}
