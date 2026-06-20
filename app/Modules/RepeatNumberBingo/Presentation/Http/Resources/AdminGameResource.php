<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources;

use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-only payload — exposes internal fields like settings and created_by.
 *
 * @mixin Game
 */
final class AdminGameResource extends JsonResource
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
                'auto_draw_enabled' => $this->auto_draw_enabled,
            ],
            'settings' => $this->settings,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
