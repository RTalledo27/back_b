<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\Commerce\Domain\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminOrdersQuery
{
    /**
     * @var list<string>
     */
    public const ALLOWED_STATUS_FILTERS = [
        'pending', 'payment_submitted', 'paid', 'rejected', 'expired', 'cancelled', 'refunded',
    ];

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(?string $status, ?string $gameId, int $perPage = 20): LengthAwarePaginator
    {
        $query = Order::query()
            ->with([
                'user:id,name,email',
                'game:id,slug,name',
                'payment:id,order_id,status,amount_cents,currency,submitted_at',
            ]);

        if ($status !== null && in_array($status, self::ALLOWED_STATUS_FILTERS, true)) {
            $query->where('status', $status);
        }

        if ($gameId !== null) {
            $query->where('game_id', $gameId);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
