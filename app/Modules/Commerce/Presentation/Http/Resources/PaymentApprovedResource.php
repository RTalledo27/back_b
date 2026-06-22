<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources;

use App\Modules\Commerce\Application\DTOs\ApprovePaymentResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentApprovedResource extends JsonResource
{
    public static $wrap = 'data';

    public function __construct(ApprovePaymentResult $result)
    {
        parent::__construct($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApprovePaymentResult $r */
        $r = $this->resource;

        return [
            'payment' => [
                'id' => $r->paymentId,
                'status' => $r->paymentStatus,
                'reviewed_at' => $r->reviewedAt,
                'reviewer_user_id' => $r->reviewerUserId,
            ],
            'order' => [
                'id' => $r->orderId,
                'status' => $r->orderStatus,
                'paid_at' => $r->paidAt,
            ],
            'entries' => [
                'ids' => $r->gameEntryIds,
                'count' => count($r->gameEntryIds),
            ],
            'allocations' => [
                'ids' => $r->purchaseAllocationIds,
            ],
            'numbers' => $r->numbers,
            'game_number_ids' => $r->gameNumberIds,
        ];
    }
}
