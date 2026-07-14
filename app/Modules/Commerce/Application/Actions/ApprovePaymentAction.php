<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\ApplyApprovedPaymentTransitionData;
use App\Modules\Commerce\Application\DTOs\ApplyApprovedPaymentTransitionResult;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentData;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentResult;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Administrative adapter for the shared approved-payment transition.
 *
 * Administrative validation remains in the request, policy and controller.
 * The shared transition owns the canonical locks and business mutations so
 * gateway settlement cannot maintain a second approval implementation.
 */
final class ApprovePaymentAction
{
    public function __construct(private readonly ApplyApprovedPaymentTransitionAction $transition) {}

    public function execute(ApprovePaymentData $data): ApprovePaymentResult
    {
        return DB::transaction(
            fn (): ApprovePaymentResult => $this->executeWithinTransaction($data),
        );
    }

    public function executeWithinTransaction(ApprovePaymentData $data): ApprovePaymentResult
    {
        $result = $this->transition->executeWithinTransaction(new ApplyApprovedPaymentTransitionData(
            paymentId: $data->paymentId,
            reviewerUserId: $data->reviewerUserId,
            notes: $data->notes,
            origin: 'manual',
        ));

        if ($result->reviewerUserId === null) {
            throw new LogicException('Manual payment approval requires a reviewer.');
        }

        return $this->toApprovePaymentResult($result);
    }

    private function toApprovePaymentResult(ApplyApprovedPaymentTransitionResult $result): ApprovePaymentResult
    {
        return new ApprovePaymentResult(
            paymentId: $result->paymentId,
            orderId: $result->orderId,
            gameId: $result->gameId,
            buyerUserId: $result->buyerUserId,
            reviewerUserId: $result->reviewerUserId,
            orderStatus: $result->orderStatus,
            paymentStatus: $result->paymentStatus,
            paidAt: $result->paidAt,
            reviewedAt: $result->reviewedAt,
            gameEntryIds: $result->gameEntryIds,
            purchaseAllocationIds: $result->purchaseAllocationIds,
            gameNumberIds: $result->gameNumberIds,
            numbers: $result->numbers,
            wasTransitionApplied: $result->wasTransitionApplied,
        );
    }
}
