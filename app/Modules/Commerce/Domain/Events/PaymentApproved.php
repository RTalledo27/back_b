<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by ApprovePaymentController AFTER the business transaction
 * commits. Not implementing ShouldDispatchAfterCommit on purpose: the
 * caller controls timing and swallows listener errors so they cannot
 * trigger compensation of an already-persisted approval.
 */
final class PaymentApproved
{
    use Dispatchable;

    /**
     * @param  list<string>  $gameEntryIds
     */
    public function __construct(
        public readonly string $paymentId,
        public readonly string $orderId,
        public readonly string $gameId,
        public readonly int $buyerUserId,
        public readonly int $reviewerUserId,
        public readonly array $gameEntryIds,
    ) {}
}
