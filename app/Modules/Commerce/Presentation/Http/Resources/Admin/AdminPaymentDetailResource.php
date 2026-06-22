<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\Commerce\Domain\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
final class AdminPaymentDetailResource extends JsonResource
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
            'reviewed_by' => $this->reviewed_by,
            'rejection_reason' => $this->rejection_reason,
            'reviewer' => $this->whenLoaded('reviewer', fn (): ?array => $this->reviewer === null ? null : [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
                'email' => $this->reviewer->email,
            ]),
            'order' => $this->whenLoaded('order', fn (): array => [
                'id' => $this->order->id,
                'status' => $this->order->status->value,
                'subtotal_cents' => $this->order->subtotal_cents,
                'total_cents' => $this->order->total_cents,
                'currency' => $this->order->currency,
                'expires_at' => $this->order->expires_at?->toIso8601String(),
                'paid_at' => $this->order->paid_at?->toIso8601String(),
                'created_at' => $this->order->created_at?->toIso8601String(),
                'user' => $this->order->relationLoaded('user') && $this->order->user !== null ? [
                    'id' => $this->order->user->id,
                    'name' => $this->order->user->name,
                    'email' => $this->order->user->email,
                ] : null,
                'game' => $this->order->relationLoaded('game') && $this->order->game !== null ? [
                    'id' => $this->order->game->id,
                    'slug' => $this->order->game->slug,
                    'name' => $this->order->game->name,
                ] : null,
                'items' => $this->order->relationLoaded('items') ? $this->order->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'game_number_id' => $item->game_number_id,
                    'unit_price_cents' => $item->unit_price_cents,
                    'number' => $item->relationLoaded('gameNumber') && $item->gameNumber !== null
                        ? (int) $item->gameNumber->number : null,
                    'number_status' => $item->relationLoaded('gameNumber') && $item->gameNumber !== null
                        ? $item->gameNumber->status->value : null,
                ])->all() : null,
            ]),
            'documents' => $this->whenLoaded('documents', fn (): array => $this->documents->map(fn ($doc): array => [
                'id' => $doc->id,
                'original_filename' => $doc->original_filename,
                'mime_type' => $doc->mime_type,
                'size_bytes' => $doc->size_bytes,
                'sha256' => $doc->sha256,
                'uploaded_by' => $doc->uploaded_by,
                'created_at' => $doc->created_at?->toIso8601String(),
                'download_url' => route('admin.payment-document.download', [
                    'payment' => $this->id,
                    'document' => $doc->id,
                ]),
                'uploader' => $doc->relationLoaded('uploader') && $doc->uploader !== null ? [
                    'id' => $doc->uploader->id,
                    'name' => $doc->uploader->name,
                    'email' => $doc->uploader->email,
                ] : null,
            ])->all()),
        ];
    }
}
