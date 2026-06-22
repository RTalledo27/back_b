<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by ExpirePendingOrdersAction (or any caller of ExpireOrderAction)
 * AFTER the expiration transaction commits, and only when the outcome was
 * a fresh `Expired`. Replays / no-ops do not dispatch.
 *
 * Plain Dispatchable on purpose: the caller controls timing and wraps
 * dispatch in a try/catch+report block so a failing listener cannot
 * revert the already-committed expiration.
 */
final class OrderReservationsExpired
{
    use Dispatchable;

    /**
     * @param  list<string>  $gameNumberIds
     * @param  list<int>  $numbers
     */
    public function __construct(
        public readonly string $orderId,
        public readonly ?string $paymentId,
        public readonly string $gameId,
        public readonly int $userId,
        public readonly array $gameNumberIds,
        public readonly array $numbers,
        public readonly string $expiredAt,
    ) {}
}
