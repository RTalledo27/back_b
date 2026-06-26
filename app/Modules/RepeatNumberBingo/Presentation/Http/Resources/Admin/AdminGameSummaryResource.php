<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin listing row. Exposes aggregate counts but no player PII, no settings,
 * no payment evidence paths, no OAuth/invitation data.
 *
 * @property-read Game $resource
 */
final class AdminGameSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $game = $this->resource;

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
            'numbers' => [
                'total' => $game->number_max - $game->number_min + 1,
                'sold' => (int) ($game->sold_count ?? 0),
                'reserved' => (int) ($game->reserved_count ?? 0),
                'available' => (int) ($game->available_count ?? 0),
            ],
            'ops' => [
                'draws_total' => (int) ($game->draws_total ?? 0),
                'orders_pending' => (int) ($game->orders_pending_count ?? 0),
                'payments_under_review' => (int) ($game->payments_under_review_count ?? 0),
                'entries_confirmed' => (int) ($game->entries_confirmed_count ?? 0),
            ],
            'created_by' => $game->created_by,
            'created_at' => $game->created_at->utc()->toIso8601String(),
        ];
    }
}
