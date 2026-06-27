<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class WinnerPayoutRegistered
{
    use Dispatchable;

    public function __construct(
        public readonly string $payoutId,
        public readonly string $gameWinnerId,
        public readonly string $gameId,
        public readonly int $winnerUserId,
        public readonly int $actorUserId,
        public readonly int $amountCents,
        public readonly string $currency,
        public readonly string $externalReference,
        public readonly string $processedAt,
    ) {}
}
