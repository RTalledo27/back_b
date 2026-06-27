<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class RefundOrderResult
{
    /**
     * @param  list<string>  $gameEntryIds
     * @param  list<string>  $gameNumberIds
     * @param  list<int>  $numbers
     */
    public function __construct(
        public string $refundId,
        public string $orderId,
        public string $paymentId,
        public string $gameId,
        public int $buyerUserId,
        public int $actorUserId,
        public int $refundedCents,
        public string $currency,
        public string $reason,
        public string $processedAt,
        public string $createdAt,
        public array $gameEntryIds,
        public array $gameNumberIds,
        public array $numbers,
        public bool $wasAlreadyRefunded,
    ) {}
}
