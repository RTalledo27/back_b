<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Public\PublicGameDrawResource;
use App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Public\PublicGameWinnerResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing payload. Never exposes internal admin-only data (e.g., the
 * creator user id, raw settings, internal jsonb config).
 *
 * @mixin Game
 */
final class PublicGameResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status->value,
            'number_range' => [
                'min' => $this->number_min,
                'max' => $this->number_max,
                'hits_required' => $this->hits_required,
            ],
            'ticket_price' => [
                'amount_cents' => $this->ticket_price_cents,
                'currency' => $this->currency,
            ],
            'prize' => [
                'amount_cents' => $this->prize_cents,
                'currency' => $this->currency,
            ],
            'schedule' => [
                'sales_opens_at' => $this->sales_opens_at?->utc()->toIso8601String(),
                'sales_closes_at' => $this->sales_closes_at?->utc()->toIso8601String(),
                'scheduled_start_at' => $this->scheduled_start_at?->utc()->toIso8601String(),
                'draw_interval_seconds' => $this->draw_interval_seconds,
                'next_draw_at' => $this->auto_draw_enabled
                    && $this->status === GameStatus::Running
                    ? $this->next_draw_at?->utc()->toIso8601String()
                    : null,
            ],
            'lifecycle' => [
                'started_at' => $this->started_at?->utc()->toIso8601String(),
                'paused_at' => $this->paused_at?->utc()->toIso8601String(),
                'completed_at' => $this->completed_at?->utc()->toIso8601String(),
            ],
            'latest_draw' => $this->when(
                $this->resource->relationLoaded('latestDraw'),
                fn (): ?PublicGameDrawResource => $this->latestDraw !== null
                    ? new PublicGameDrawResource($this->latestDraw)
                    : null,
            ),
            'winner' => $this->when(
                $this->resource->relationLoaded('winner'),
                fn (): ?PublicGameWinnerResource => $this->winner !== null
                    ? new PublicGameWinnerResource($this->winner)
                    : null,
            ),
        ];
    }
}
