<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Enums;

enum GameNumberStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';

    /**
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Available => [self::Reserved],
            self::Reserved => [self::Available, self::Sold],
            self::Sold => [self::Available],  // Allowed for admin refunds: returns the number to the pool
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNextStates(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNextStates() === [];
    }
}
