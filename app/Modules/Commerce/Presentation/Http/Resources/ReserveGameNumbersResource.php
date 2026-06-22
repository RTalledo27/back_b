<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources;

use App\Modules\Commerce\Application\DTOs\ReserveGameNumbersResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps the Action's CommandResult into the public JSON shape.
 *
 * Order / Payment statuses are deterministic at creation time and on
 * idempotent replay (the cached payload represents the same instant);
 * we render them as constants here without re-querying.
 */
final class ReserveGameNumbersResource extends JsonResource
{
    public static $wrap = 'data';

    public function __construct(ReserveGameNumbersResult $result)
    {
        parent::__construct($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ReserveGameNumbersResult $r */
        $r = $this->resource;

        return [
            'order' => [
                'id' => $r->orderId,
                'game_id' => $r->gameId,
                'status' => 'pending',
                'subtotal_cents' => $r->subtotalCents,
                'total_cents' => $r->totalCents,
                'currency' => $r->currency,
                'expires_at' => $r->expiresAt,
            ],
            'numbers' => $r->numbers,
            'game_number_ids' => $r->gameNumberIds,
            'reservation_ids' => $r->reservationIds,
            'payment' => [
                'id' => $r->paymentId,
                'status' => 'pending',
                'amount_cents' => $r->totalCents,
                'currency' => $r->currency,
            ],
        ];
    }
}
