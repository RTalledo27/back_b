<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Active reservations owned by the authenticated user. A reservation
 * only exists while the parent order is in a holding state (pending or
 * payment_submitted) — once approved/rejected/cancelled/expired the
 * reservation row is deleted by its action.
 *
 * Eager loads:
 *   - order (to expose status / expires_at without N+1)
 *   - gameNumber.game (number + game slug/name)
 */
final class ListMyReservationsQuery
{
    /**
     * @return LengthAwarePaginator<int, NumberReservation>
     */
    public function paginate(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return NumberReservation::query()
            ->with([
                'order:id,user_id,status,expires_at,total_cents,currency',
                'gameNumber:id,game_id,number,status',
                'gameNumber.game:id,slug,name',
            ])
            ->whereHas('order', function ($q) use ($userId): void {
                $q->where('user_id', $userId)
                    ->whereIn('status', [
                        OrderStatus::Pending->value,
                        OrderStatus::PaymentSubmitted->value,
                    ]);
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
