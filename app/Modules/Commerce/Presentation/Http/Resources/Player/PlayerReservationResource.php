<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Player;

use App\Modules\Commerce\Domain\Models\NumberReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NumberReservation
 */
final class PlayerReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'game_number_id' => $this->game_number_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'order' => $this->whenLoaded('order', fn (): array => [
                'id' => $this->order->id,
                'status' => $this->order->status->value,
                'expires_at' => $this->order->expires_at?->toIso8601String(),
                'total_cents' => $this->order->total_cents,
                'currency' => $this->order->currency,
            ]),
            'game_number' => $this->whenLoaded('gameNumber', fn (): array => [
                'id' => $this->gameNumber->id,
                'number' => (int) $this->gameNumber->number,
                'status' => $this->gameNumber->status->value,
                'game' => $this->whenLoaded('game', null, function (): ?array {
                    $game = $this->gameNumber->game;

                    return $game === null ? null : [
                        'id' => $game->id,
                        'slug' => $game->slug,
                        'name' => $game->name,
                    ];
                }),
            ]),
        ];
    }
}
