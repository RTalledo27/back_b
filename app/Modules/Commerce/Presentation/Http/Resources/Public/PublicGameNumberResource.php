<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Public;

use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view of a game number — number + status ONLY.
 *
 * Deliberately omits: id, game_id, owner identity, order id, payment id,
 * reservation id, timestamps. The internal `id` is not exposed either,
 * to keep the public payload purely about state.
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
            'number' => (int) $this->number,
            'status' => $this->status->value,
        ];
    }
}
