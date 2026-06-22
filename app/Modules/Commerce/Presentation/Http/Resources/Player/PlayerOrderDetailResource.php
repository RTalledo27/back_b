<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Player;

use App\Modules\Commerce\Domain\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
final class PlayerOrderDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'subtotal_cents' => $this->subtotal_cents,
            'total_cents' => $this->total_cents,
            'currency' => $this->currency,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'expired_at' => $this->expired_at?->toIso8601String(),
            'game' => $this->whenLoaded('game', fn (): ?array => $this->game === null ? null : [
                'id' => $this->game->id,
                'slug' => $this->game->slug,
                'name' => $this->game->name,
            ]),
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'game_number_id' => $item->game_number_id,
                'unit_price_cents' => $item->unit_price_cents,
                'number' => $item->relationLoaded('gameNumber') && $item->gameNumber !== null
                    ? (int) $item->gameNumber->number : null,
                'number_status' => $item->relationLoaded('gameNumber') && $item->gameNumber !== null
                    ? $item->gameNumber->status->value : null,
            ])->all()),
            'reservations' => $this->whenLoaded('reservations', fn (): array => $this->reservations->map(fn ($r): array => [
                'id' => $r->id,
                'game_number_id' => $r->game_number_id,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->all()),
            'payment' => $this->whenLoaded('payment', fn (): ?array => $this->payment === null ? null : [
                'id' => $this->payment->id,
                'status' => $this->payment->status->value,
                'amount_cents' => $this->payment->amount_cents,
                'currency' => $this->payment->currency,
                'submitted_at' => $this->payment->submitted_at?->toIso8601String(),
                'reviewed_at' => $this->payment->reviewed_at?->toIso8601String(),
                'rejection_reason' => $this->payment->rejection_reason,
            ]),
        ];
    }
}
