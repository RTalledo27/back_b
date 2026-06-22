<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\Commerce\Domain\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
final class AdminPaymentListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn (): ?array => $this->order === null ? null : [
                'id' => $this->order->id,
                'user_id' => $this->order->user_id,
                'game_id' => $this->order->game_id,
                'status' => $this->order->status->value,
                'total_cents' => $this->order->total_cents,
                'currency' => $this->order->currency,
                'expires_at' => $this->order->expires_at?->toIso8601String(),
                'game' => $this->order->relationLoaded('game') && $this->order->game !== null ? [
                    'id' => $this->order->game->id,
                    'slug' => $this->order->game->slug,
                    'name' => $this->order->game->name,
                ] : null,
            ]),
        ];
    }
}
