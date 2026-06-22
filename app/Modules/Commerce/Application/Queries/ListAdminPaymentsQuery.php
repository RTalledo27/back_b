<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\Commerce\Domain\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListAdminPaymentsQuery
{
    /**
     * @var list<string>
     */
    public const ALLOWED_STATUS_FILTERS = [
        'pending', 'under_review', 'approved', 'rejected', 'cancelled', 'refunded',
    ];

    /**
     * @return LengthAwarePaginator<int, Payment>
     */
    public function paginate(?string $status, int $perPage = 20): LengthAwarePaginator
    {
        $query = Payment::query()
            ->with([
                'order:id,user_id,game_id,status,total_cents,currency,expires_at',
                'order.game:id,slug,name',
            ]);

        if ($status !== null && in_array($status, self::ALLOWED_STATUS_FILTERS, true)) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('submitted_at')->orderByDesc('created_at')->paginate($perPage);
    }
}
