<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class OrderCancelledByUser
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
        public readonly string $cancelledAt,
    ) {}
}
