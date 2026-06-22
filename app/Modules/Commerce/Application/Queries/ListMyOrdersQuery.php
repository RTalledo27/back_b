<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\Commerce\Domain\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListMyOrdersQuery
{
    /**
     * Allow-list of filter values for the optional `status` query string.
     * Any other value coming from the client is silently ignored.
     *
     * @var list<string>
     */
    public const ALLOWED_STATUS_FILTERS = [
        'pending', 'payment_submitted', 'paid', 'rejected', 'expired', 'cancelled', 'refunded',
    ];

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(int $userId, ?string $status, int $perPage = 20): LengthAwarePaginator
    {
        $query = Order::query()
            ->with([
                'payment:id,order_id,status,amount_cents,currency,submitted_at',
                'items:id,order_id,game_number_id,unit_price_cents',
            ])
            ->where('user_id', $userId);

        if ($status !== null && in_array($status, self::ALLOWED_STATUS_FILTERS, true)) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
