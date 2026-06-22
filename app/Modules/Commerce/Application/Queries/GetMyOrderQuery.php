<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\Commerce\Domain\Models\Order;

final class GetMyOrderQuery
{
    public function findForUser(string $orderId, int $userId): ?Order
    {
        return Order::query()
            ->with([
                'payment:id,order_id,status,amount_cents,currency,submitted_at,reviewed_at,rejection_reason',
                'items.gameNumber:id,game_id,number,status',
                'reservations:id,order_id,game_number_id,created_at',
                'game:id,slug,name',
            ])
            ->whereKey($orderId)
            ->where('user_id', $userId)
            ->first();
    }
}
