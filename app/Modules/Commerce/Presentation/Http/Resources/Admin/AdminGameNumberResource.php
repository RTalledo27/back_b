<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GameNumber
 *
 * Pre-decorated extra payload (active_reservation / sold_entry) is built
 * by ListGameNumbersAdminController via batched joins to avoid N+1.
 */
final class AdminGameNumberResource extends JsonResource
{
    /**
     * @param  array{
     *   active_reservation?: ?array<string, mixed>,
     *   sold_entry?: ?array<string, mixed>,
     * }  $extra
     */
    public function __construct(GameNumber $gameNumber, private readonly array $extra = [])
    {
        parent::__construct($gameNumber);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => (int) $this->number,
            'status' => $this->status->value,
            'active_reservation' => $this->extra['active_reservation'] ?? null,
            'sold_entry' => $this->extra['sold_entry'] ?? null,
        ];
    }
}
