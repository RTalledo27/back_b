<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Enums;

enum GameStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case SalesOpen = 'sales_open';
    case SalesClosed = 'sales_closed';
    case Running = 'running';
    case Paused = 'paused';
    case Resolving = 'resolving';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Single source of truth for state transitions.
     *
     * Note: `scheduled` is NOT a state — the scheduled start time is an
     * attribute (`games.scheduled_start_at`) that can be set whenever the
     * game is in published / sales_open / sales_closed. The transition into
     * `running` happens directly from `sales_closed` (manual or auto draw
     * job) once `scheduled_start_at` is reached.
     *
     * @return list<self>
     */
    public function allowedNextStates(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Cancelled],
            self::Published => [self::SalesOpen, self::Cancelled],
            self::SalesOpen => [self::SalesClosed, self::Cancelled],
            self::SalesClosed => [self::Running, self::Cancelled],
            self::Running => [self::Paused, self::Resolving],
            self::Paused => [self::Running, self::Cancelled],
            self::Resolving => [self::Completed],
            self::Completed, self::Cancelled => [],
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

    /**
     * States in which the scheduled start time may be (re)configured.
     *
     * @return list<self>
     */
    public static function statesWhereScheduledStartIsConfigurable(): array
    {
        return [self::Published, self::SalesOpen, self::SalesClosed];
    }
}
