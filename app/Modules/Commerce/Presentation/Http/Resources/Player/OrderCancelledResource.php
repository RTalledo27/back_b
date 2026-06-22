<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Player;

use App\Modules\Commerce\Application\DTOs\CancelOrderResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrderCancelledResource extends JsonResource
{
    public static $wrap = 'data';

    public function __construct(CancelOrderResult $result)
    {
        parent::__construct($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CancelOrderResult $r */
        $r = $this->resource;

        return [
            'order' => [
                'id' => $r->orderId,
                'status' => 'cancelled',
                'cancelled_at' => $r->cancelledAt,
            ],
            'payment' => $r->paymentId === null ? null : [
                'id' => $r->paymentId,
                'status' => 'cancelled',
            ],
            'released' => [
                'numbers' => $r->numbers,
                'game_number_ids' => $r->gameNumberIds,
            ],
        ];
    }
}
