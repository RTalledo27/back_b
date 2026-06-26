<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full admin snapshot. Exposes settings, engine state, commerce aggregates
 * by real enum status, and draw projection. Winner shows user_id only —
 * no player PII (name, email). Payment evidence paths are never included.
 *
 * @property-read Game $resource
 */
final class AdminGameDetailResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $game = $this->resource;

        /** @var array<string, mixed> $commerce */
        $commerce = $game->getAttribute('commerce') ?? [];

        /** @var array<string, mixed> $projection */
        $projection = $game->getAttribute('projection') ?? [];

        return [
            'id' => $game->id,
            'slug' => $game->slug,
            'name' => $game->name,
            'description' => $game->description,
            'status' => $game->status->value,
            'number_range' => [
                'min' => $game->number_min,
                'max' => $game->number_max,
                'hits_required' => $game->hits_required,
            ],
            'ticket_price' => [
                'amount_cents' => $game->ticket_price_cents,
                'currency' => $game->currency,
            ],
            'prize' => [
                'amount_cents' => $game->prize_cents,
                'currency' => $game->currency,
            ],
            'schedule' => [
                'sales_opens_at' => $game->sales_opens_at?->utc()->toIso8601String(),
                'sales_closes_at' => $game->sales_closes_at?->utc()->toIso8601String(),
                'scheduled_start_at' => $game->scheduled_start_at?->utc()->toIso8601String(),
                'draw_interval_seconds' => $game->draw_interval_seconds,
                'auto_draw_enabled' => $game->auto_draw_enabled,
            ],
            'lifecycle' => [
                'started_at' => $game->started_at?->utc()->toIso8601String(),
                'paused_at' => $game->paused_at?->utc()->toIso8601String(),
                'completed_at' => $game->completed_at?->utc()->toIso8601String(),
            ],
            'engine' => [
                'next_draw_at' => $game->next_draw_at?->utc()->toIso8601String(),
                'last_consumed_tick_at' => $game->last_consumed_tick_at?->utc()->toIso8601String(),
            ],
            'numbers' => [
                'total' => $game->number_max - $game->number_min + 1,
                'sold' => (int) ($game->sold_count ?? 0),
                'reserved' => (int) ($game->reserved_count ?? 0),
                'available' => (int) ($game->available_count ?? 0),
            ],
            'settings' => $game->settings,
            'latest_draw' => $this->when(
                $game->relationLoaded('latestDraw'),
                fn (): ?array => $game->latestDraw !== null
                    ? [
                        'sequence' => $game->latestDraw->sequence,
                        'number' => $game->latestDraw->drawn_number,
                        'drawn_at' => $game->latestDraw->drawn_at->utc()->toIso8601String(),
                    ]
                    : null,
            ),
            'winner' => $this->when(
                $game->relationLoaded('winner'),
                fn (): ?array => $game->winner !== null
                    ? [
                        'user_id' => (int) $game->winner->user_id,
                        'game_number_id' => $game->winner->game_number_id,
                        'winning_number' => $game->winner->gameNumber?->number !== null
                            ? (int) $game->winner->gameNumber->number
                            : null,
                        'game_draw_id' => $game->winner->game_draw_id,
                        'winning_draw_sequence' => $game->winner->draw?->sequence !== null
                            ? (int) $game->winner->draw->sequence
                            : null,
                        'winning_hits' => (int) $game->winner->winning_hits,
                        'won_at' => $game->winner->won_at->utc()->toIso8601String(),
                    ]
                    : null,
            ),
            'commerce' => $commerce,
            'projection' => $projection,
            'created_by' => $game->created_by,
            'created_at' => $game->created_at->utc()->toIso8601String(),
        ];
    }
}
