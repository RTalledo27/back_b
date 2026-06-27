<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class OrderRefunded
{
    use Dispatchable;

    /**
     * @param  list<string>  $gameEntryIds
     * @param  list<string>  $gameNumberIds
     * @param  list<int>  $numbers
     */
    public function __construct(
        public readonly string $refundId,
        public readonly string $orderId,
        public readonly string $paymentId,
        public readonly string $gameId,
        public readonly int $buyerUserId,
        public readonly int $actorUserId,
        public readonly int $refundedCents,
        public readonly string $currency,
        public readonly string $reason,
        public readonly array $gameEntryIds,
        public readonly array $gameNumberIds,
        public readonly array $numbers,
        public readonly string $processedAt,
    ) {}
}
