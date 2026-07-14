<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use App\Modules\Commerce\Application\DTOs\ApplyApprovedPaymentTransitionResult;

final readonly class GatewayPaymentSettlementResponse
{
    /**
     * @param  list<string>  $gameEntryIds
     * @param  list<string>  $purchaseAllocationIds
     * @param  list<string>  $gameNumberIds
     * @param  list<int>  $numbers
     */
    public function __construct(
        public string $transactionId,
        public string $paymentId,
        public string $orderId,
        public string $gameId,
        public string $paymentStatus,
        public string $orderStatus,
        public string $paidAt,
        public array $gameEntryIds,
        public array $purchaseAllocationIds,
        public array $gameNumberIds,
        public array $numbers,
        public bool $wasSettlementApplied,
    ) {}

    public static function fromTransition(
        string $transactionId,
        ApplyApprovedPaymentTransitionResult $result,
    ): self {
        return new self(
            transactionId: $transactionId,
            paymentId: $result->paymentId,
            orderId: $result->orderId,
            gameId: $result->gameId,
            paymentStatus: $result->paymentStatus,
            orderStatus: $result->orderStatus,
            paidAt: $result->paidAt,
            gameEntryIds: $result->gameEntryIds,
            purchaseAllocationIds: $result->purchaseAllocationIds,
            gameNumberIds: $result->gameNumberIds,
            numbers: $result->numbers,
            wasSettlementApplied: $result->wasTransitionApplied,
        );
    }
}
