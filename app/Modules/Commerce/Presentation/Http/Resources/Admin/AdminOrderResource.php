<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\Commerce\Domain\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
final class AdminOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'game_id' => $this->game_id,
            'status' => $this->status->value,
            'subtotal_cents' => $this->subtotal_cents,
            'total_cents' => $this->total_cents,
            'currency' => $this->currency,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'expired_at' => $this->expired_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'game' => $this->whenLoaded('game', fn (): ?array => $this->game === null ? null : [
                'id' => $this->game->id,
                'slug' => $this->game->slug,
                'name' => $this->game->name,
            ]),
            'payment' => $this->whenLoaded('payment', fn (): ?array => $this->payment === null ? null : [
                'id' => $this->payment->id,
                'status' => $this->payment->status->value,
                'amount_cents' => $this->payment->amount_cents,
                'currency' => $this->payment->currency,
                'submitted_at' => $this->payment->submitted_at?->toIso8601String(),
            ]),
        ];
    }
}
