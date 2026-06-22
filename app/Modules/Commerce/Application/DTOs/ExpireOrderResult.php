<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

/**
 * Result of attempting to expire one pending order.
 *
 * `outcome` is the source of truth; the other fields may be empty when
 * the action skipped (`NotDue`, `SkippedStateChanged`). `expiredAt` is
 * populated only on `Expired` and `AlreadyExpired`.
 */
final readonly class ExpireOrderResult
{
    /**
     * @param  list<string>  $gameNumberIds
     * @param  list<int>  $numbers
     */
    public function __construct(
        public string $orderId,
        public ?string $paymentId,
        public string $gameId,
        public int $userId,
        public array $gameNumberIds,
        public array $numbers,
        public ?string $expiredAt,
        public ExpireOrderOutcome $outcome,
    ) {}

    public function wasTransitionApplied(): bool
    {
        return $this->outcome->wasTransitionApplied();
    }
}
