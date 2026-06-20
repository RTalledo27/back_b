<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources;

use App\Modules\RepeatNumberBingo\Domain\Models\Game;
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
                'sales_opens_at' => $this->sales_opens_at?->toIso8601String(),
                'sales_closes_at' => $this->sales_closes_at?->toIso8601String(),
                'scheduled_start_at' => $this->scheduled_start_at?->toIso8601String(),
                'draw_interval_seconds' => $this->draw_interval_seconds,
            ],
        ];
    }
}
