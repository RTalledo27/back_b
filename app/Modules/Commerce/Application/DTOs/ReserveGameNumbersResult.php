<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

/**
 * Concrete CommandResult for ReserveGameNumbersAction. Holds everything
 * the Controller needs to render its Resource on both fresh execution
 * and idempotent replay — no Eloquent re-queries required.
 */
final readonly class ReserveGameNumbersResult implements CommandResult
{
    /**
     * @param  list<int>  $numbers
     * @param  list<string>  $gameNumberIds
     * @param  list<string>  $reservationIds
     */
    public function __construct(
        public string $orderId,
        public string $gameId,
        public int $userId,
        public string $paymentId,
        public array $numbers,
        public array $gameNumberIds,
        public array $reservationIds,
        public int $subtotalCents,
        public int $totalCents,
        public string $currency,
        public string $expiresAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'game_id' => $this->gameId,
            'user_id' => $this->userId,
            'payment_id' => $this->paymentId,
            'numbers' => $this->numbers,
            'game_number_ids' => $this->gameNumberIds,
            'reservation_ids' => $this->reservationIds,
            'subtotal_cents' => $this->subtotalCents,
            'total_cents' => $this->totalCents,
            'currency' => $this->currency,
            'expires_at' => $this->expiresAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            orderId: (string) $payload['order_id'],
            gameId: (string) $payload['game_id'],
            userId: (int) $payload['user_id'],
            paymentId: (string) $payload['payment_id'],
            numbers: array_values(array_map('intval', (array) $payload['numbers'])),
            gameNumberIds: array_values(array_map('strval', (array) $payload['game_number_ids'])),
            reservationIds: array_values(array_map('strval', (array) $payload['reservation_ids'])),
            subtotalCents: (int) $payload['subtotal_cents'],
            totalCents: (int) $payload['total_cents'],
            currency: (string) $payload['currency'],
            expiresAt: (string) $payload['expires_at'],
        );
    }
}
