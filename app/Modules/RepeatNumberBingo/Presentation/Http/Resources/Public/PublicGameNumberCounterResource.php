<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Presentation\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PublicGameNumberCounterResource extends JsonResource
{
    /**
     * @return array{number: int, hits_count: int, last_draw_sequence: ?int}
     */
    public function toArray(Request $request): array
    {
        /** @var \stdClass $row */
        $row = $this->resource;

        return [
            'number' => (int) $row->number,
            'hits_count' => (int) ($row->hits_count ?? 0),
            'last_draw_sequence' => $row->last_draw_sequence !== null
                ? (int) $row->last_draw_sequence
                : null,
        ];
    }
}
