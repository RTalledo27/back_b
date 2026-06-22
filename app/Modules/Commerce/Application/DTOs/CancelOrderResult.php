<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class CancelOrderResult
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
        public ?string $cancelledAt,
        public CancelOrderOutcome $outcome,
    ) {}

    public function wasTransitionApplied(): bool
    {
        return $this->outcome->wasTransitionApplied();
    }
}
