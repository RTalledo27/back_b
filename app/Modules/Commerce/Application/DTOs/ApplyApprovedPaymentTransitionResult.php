<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class ApplyApprovedPaymentTransitionResult
{
    /**
     * @param  list<string>  $gameEntryIds
     * @param  list<string>  $purchaseAllocationIds
     * @param  list<string>  $gameNumberIds
     * @param  list<int>  $numbers
     */
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public string $gameId,
        public int $buyerUserId,
        public ?int $reviewerUserId,
        public string $orderStatus,
        public string $paymentStatus,
        public string $paidAt,
        public string $reviewedAt,
        public array $gameEntryIds,
        public array $purchaseAllocationIds,
        public array $gameNumberIds,
        public array $numbers,
        public bool $wasTransitionApplied,
    ) {}
}
