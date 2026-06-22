<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Actions\CancelOrderAction;
use App\Modules\Commerce\Application\DTOs\CancelOrderData;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Presentation\Http\Requests\Player\CancelOrderRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Player\OrderCancelledResource;
use Symfony\Component\HttpFoundation\Response;

final class CancelOrderController
{
    public function __invoke(
        CancelOrderRequest $request,
        Order $order,
        CancelOrderAction $action,
    ): Response {
        $result = $action->execute(new CancelOrderData(
            orderId: $order->getKey(),
            userId: (int) $request->user()?->getKey(),
        ));

        return (new OrderCancelledResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
