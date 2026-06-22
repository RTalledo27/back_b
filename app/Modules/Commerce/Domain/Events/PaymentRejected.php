<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class PaymentRejected
{
    use Dispatchable;

    /**
     * @param  list<string>  $releasedGameNumberIds
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly string $gameId,
        public readonly int $buyerUserId,
        public readonly int $reviewerUserId,
        public readonly string $reason,
        public readonly array $releasedGameNumberIds,
    ) {}
}
