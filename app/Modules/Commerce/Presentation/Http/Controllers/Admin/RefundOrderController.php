<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\RefundOrderAction;
use App\Modules\Commerce\Application\DTOs\RefundOrderData;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\RefundOrderRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\RefundResource;
use Symfony\Component\HttpFoundation\Response;

final class RefundOrderController
{
    public function __invoke(
        RefundOrderRequest $request,
        Order $order,
        RefundOrderAction $action,
    ): Response {
        $user = $request->user();
        $actorUserId = (int) $user?->getKey();
        $reason = $request->reason();
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        $idempotencyKeyHash = hash('sha256', $idempotencyKey);

        $canonicalFingerprint = implode("\n", [
            'operation=refund',
            'order_id='.$order->getKey(),
            'actor_user_id='.$actorUserId,
            'reason='.mb_strtolower(trim($reason)),
        ]);
        $requestFingerprint = hash('sha256', $canonicalFingerprint);

        $data = new RefundOrderData(
            orderId: $order->getKey(),
            actorUserId: $actorUserId,
            reason: $reason,
            idempotencyKeyHash: $idempotencyKeyHash,
            requestFingerprint: $requestFingerprint,
        );

        $result = $action->execute($data);

        return (new RefundResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
