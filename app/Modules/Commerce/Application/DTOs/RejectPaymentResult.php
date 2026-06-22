<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class RejectPaymentResult implements CommandResult
{
    /**
     * @param  list<string>  $releasedGameNumberIds
     * @param  list<int>  $releasedNumbers
     */
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public string $gameId,
        public int $buyerUserId,
        public int $reviewerUserId,
        public string $orderStatus,
        public string $paymentStatus,
        public string $reviewedAt,
        public string $reason,
        public array $releasedGameNumberIds,
        public array $releasedNumbers,
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
            'reviewed_at' => $this->reviewedAt,
            'reason' => $this->reason,
            'released_game_number_ids' => $this->releasedGameNumberIds,
            'released_numbers' => $this->releasedNumbers,
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
            reviewedAt: (string) $payload['reviewed_at'],
            reason: (string) $payload['reason'],
            releasedGameNumberIds: array_values(array_map('strval', (array) $payload['released_game_number_ids'])),
            releasedNumbers: array_values(array_map('intval', (array) $payload['released_numbers'])),
            wasTransitionApplied: (bool) ($payload['was_transition_applied'] ?? false),
        );
    }
}
