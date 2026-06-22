<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources;

use App\Modules\Commerce\Application\DTOs\RejectPaymentResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaymentRejectedResource extends JsonResource
{
    public static $wrap = 'data';

    public function __construct(RejectPaymentResult $result)
    {
        parent::__construct($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RejectPaymentResult $r */
        $r = $this->resource;

        return [
            'payment' => [
                'id' => $r->paymentId,
                'status' => $r->paymentStatus,
                'reviewed_at' => $r->reviewedAt,
                'reviewer_user_id' => $r->reviewerUserId,
                'rejection_reason' => $r->reason,
            ],
            'order' => [
                'id' => $r->orderId,
                'status' => $r->orderStatus,
            ],
            'released' => [
                'numbers' => $r->releasedNumbers,
                'game_number_ids' => $r->releasedGameNumberIds,
            ],
        ];
    }
}
