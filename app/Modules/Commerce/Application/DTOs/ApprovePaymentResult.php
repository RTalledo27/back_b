<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

/**
 * Snapshot of an approved payment + the participations it produced.
 *
 * `wasTransitionApplied` distinguishes a fresh under_review → approved
 * transition (true) from an idempotent return on an already-approved
 * payment (false). Consumed only by the orchestration layer (executor
 * afterCommit callback) — never serialised through the public Resource.
 */
final readonly class ApprovePaymentResult implements CommandResult
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
        public int $reviewerUserId,
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'order_id' => $this->orderId,
            'game_id' => $this->gameId,
            'buyer_user_id' => $this->buyerUserId,
            'reviewer_user_id' => $this->reviewerUserId,
            'order_status' => $this->orderStatus,
            'payment_status' => $this->paymentStatus,
            'paid_at' => $this->paidAt,
            'reviewed_at' => $this->reviewedAt,
            'game_entry_ids' => $this->gameEntryIds,
            'purchase_allocation_ids' => $this->purchaseAllocationIds,
            'game_number_ids' => $this->gameNumberIds,
            'numbers' => $this->numbers,
            'was_transition_applied' => $this->wasTransitionApplied,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            paymentId: (string) $payload['payment_id'],
            orderId: (string) $payload['order_id'],
            gameId: (string) $payload['game_id'],
            buyerUserId: (int) $payload['buyer_user_id'],
            reviewerUserId: (int) $payload['reviewer_user_id'],
            orderStatus: (string) $payload['order_status'],
            paymentStatus: (string) $payload['payment_status'],
            paidAt: (string) $payload['paid_at'],
            reviewedAt: (string) $payload['reviewed_at'],
            gameEntryIds: array_values(array_map('strval', (array) $payload['game_entry_ids'])),
            purchaseAllocationIds: array_values(array_map('strval', (array) $payload['purchase_allocation_ids'])),
            gameNumberIds: array_values(array_map('strval', (array) $payload['game_number_ids'])),
            numbers: array_values(array_map('intval', (array) $payload['numbers'])),
            wasTransitionApplied: (bool) ($payload['was_transition_applied'] ?? false),
        );
    }
}
