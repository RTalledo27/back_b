<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use App\Modules\Commerce\Application\DTOs\RefundOrderResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RefundResource extends JsonResource
{
    public static $wrap = 'data';

    public function __construct(RefundOrderResult $result)
    {
        parent::__construct($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RefundOrderResult $r */
        $r = $this->resource;

        return [
            'id' => $r->refundId,
            'order_id' => $r->orderId,
            'payment_id' => $r->paymentId,
            'game_id' => $r->gameId,
            'amount_cents' => $r->refundedCents,
            'currency' => $r->currency,
            'reason' => $r->reason,
            'processed_by_user_id' => $r->actorUserId,
            'processed_at' => $r->processedAt,
            'created_at' => $r->createdAt,
            'entries' => [
                'ids' => $r->gameEntryIds,
                'count' => count($r->gameEntryIds),
            ],
            'numbers' => $r->numbers,
            'game_number_ids' => $r->gameNumberIds,
            'was_already_refunded' => $r->wasAlreadyRefunded,
        ];
    }
}
