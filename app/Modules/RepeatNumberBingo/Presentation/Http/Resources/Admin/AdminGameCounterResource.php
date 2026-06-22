<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises the LEFT-JOIN row produced by ListGameNumberCountersQuery.
 * The query already returns flat columns (no Eloquent model) so this
 * resource takes a plain stdClass row.
 */
final class AdminGameCounterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \stdClass $row */
        $row = $this->resource;

        return [
            'game_number_id' => $row->game_number_id,
            'number' => (int) $row->number,
            'status' => (string) $row->status,
            'hits_count' => (int) ($row->hits_count ?? 0),
            'last_draw_sequence' => $row->last_draw_sequence !== null
                ? (int) $row->last_draw_sequence
                : null,
        ];
    }
}
