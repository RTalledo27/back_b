<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Queries\GetMyOrderQuery;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Presentation\Http\Resources\Player\PlayerOrderDetailResource;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowMyOrderController
{
    public function __invoke(
        Request $request,
        Order $order,
        GetMyOrderQuery $query,
    ): PlayerOrderDetailResource {
        $owned = $query->findForUser($order->getKey(), (int) $request->user()?->getKey());

        if ($owned === null) {
            // Hide existence of orders that don't belong to this user.
            throw new NotFoundHttpException('Order not found.');
        }

        return new PlayerOrderDetailResource($owned);
    }
}
