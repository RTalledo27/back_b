<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Aggregate event for a multi-number reservation. Carries enough context
 * for listeners (broadcasting, notifications, projections) without coupling
 * them to ReserveGameNumbersAction's internals.
 */
final class GameNumbersReserved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $gameNumberIds
     * @param  list<int>  $numbers
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $gameId,
        public readonly int $userId,
        public readonly array $gameNumberIds,
        public readonly array $numbers,
        public readonly string $expiresAt,
    ) {}
}
