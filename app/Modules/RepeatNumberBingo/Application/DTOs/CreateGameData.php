<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameConfiguration;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\BingoNumberRange;
use App\Modules\Shared\Domain\ValueObjects\Money;
use DateTimeImmutable;

/**
 * Validated input for CreateGameAction. Field-level validation lives in the
 * FormRequest; invariants that protect the domain (range, money, dates)
 * live here so this DTO is safe to use from any caller (HTTP, CLI, seeders).
 */
final readonly class CreateGameData
{
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $description,
        public BingoNumberRange $range,
        public Money $ticketPrice,
        public Money $prize,
        public int $drawIntervalSeconds,
        public bool $autoDrawEnabled,
        public ?DateTimeImmutable $salesOpensAt,
        public ?DateTimeImmutable $salesClosesAt,
        public ?DateTimeImmutable $scheduledStartAt,
        /** @var array<string,mixed>|null */
        public ?array $settings,
        public ?int $createdBy,
    ) {
        if ($ticketPrice->currency !== $prize->currency) {
            throw new InvalidGameConfiguration(
                "Ticket price currency ({$ticketPrice->currency}) "
                ."must match prize currency ({$prize->currency})."
            );
        }

        if ($drawIntervalSeconds < 1) {
            throw new InvalidGameConfiguration('Draw interval must be at least 1 second.');
        }

        if ($salesOpensAt !== null && $salesClosesAt !== null && $salesClosesAt <= $salesOpensAt) {
            throw new InvalidGameConfiguration('Sales close must be after sales open.');
        }

        if ($scheduledStartAt !== null && $salesClosesAt !== null && $scheduledStartAt < $salesClosesAt) {
            throw new InvalidGameConfiguration('Scheduled start cannot be before sales close.');
        }
    }
}
