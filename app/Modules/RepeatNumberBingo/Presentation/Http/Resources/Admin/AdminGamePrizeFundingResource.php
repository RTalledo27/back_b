<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GamePrizeFunding */
final class AdminGamePrizeFundingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'game_id' => $this->game_id,
            'status' => $this->status->value,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'funded_at' => $this->funded_at?->toIso8601String(),
            'reserved_at' => $this->reserved_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'documents' => $this->whenLoaded(
                'documents',
                fn (): array => $this->documents->map(fn ($document): array => [
                    'id' => $document->id,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => $document->size_bytes,
                    'created_at' => $document->created_at?->toIso8601String(),
                ])->values()->all(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
