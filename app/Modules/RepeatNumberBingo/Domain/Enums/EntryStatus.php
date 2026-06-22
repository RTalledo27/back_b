<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Enums;

enum EntryStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Winner = 'winner';

    /**
     * Phase 2 only writes Confirmed at creation. Winner is set by Phase 3
     * (ResolveGameWinnerAction). Cancelled / Refunded come from admin
     * operations in later phases. The matrix is declared upfront so the
     * DB CHECK constraint never needs relaxing.
     *
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Confirmed => [self::Winner, self::Cancelled, self::Refunded],
            self::Winner, self::Cancelled, self::Refunded => [],
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
