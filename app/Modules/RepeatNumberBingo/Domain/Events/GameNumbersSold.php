<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Aggregate event for "one or more numbers were just sold to a single
 * user via the approval of a single payment". Owned by RepeatNumberBingo
 * — the fact lives in the game domain — and dispatched by Commerce after
 * ApprovePaymentAction's transaction commits.
 *
 * Carries no order_id / payment_id intentionally: RNB listeners do not
 * need to know about commerce. Anyone who needs that mapping joins via
 * purchase_allocations from Commerce.
 */
final class GameNumbersSold
{
    use Dispatchable;

    /**
     * @param  list<string>  $gameNumberIds
     * @param  list<int>  $numbers
     * @param  list<string>  $gameEntryIds
     */
    public function __construct(
        public readonly string $gameId,
        public readonly int $userId,
        public readonly array $gameNumberIds,
        public readonly array $numbers,
        public readonly array $gameEntryIds,
    ) {}
}
