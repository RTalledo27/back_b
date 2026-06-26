<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Public;

use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view of a game number.
 *
 * Exposes the public reservation contract only: `id`, `number`, `status`.
 * Deliberately omits: game_id, owner identity, order id, payment id,
 * reservation id, timestamps and any other internal metadata.
 *
 * @mixin GameNumber
 */
final class PublicGameNumberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => (int) $this->number,
            'status' => $this->status->value,
        ];
    }
}
