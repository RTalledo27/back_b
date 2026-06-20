<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GameStatusTransitionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: GameStatus, 1: GameStatus, 2: bool}>
     */
    public static function transitions(): iterable
    {
        // happy paths
        yield 'draft -> published' => [GameStatus::Draft, GameStatus::Published, true];
        yield 'published -> sales_open' => [GameStatus::Published, GameStatus::SalesOpen, true];
        yield 'sales_open -> sales_closed' => [GameStatus::SalesOpen, GameStatus::SalesClosed, true];
        yield 'sales_closed -> running' => [GameStatus::SalesClosed, GameStatus::Running, true];
        yield 'running -> paused' => [GameStatus::Running, GameStatus::Paused, true];
        yield 'paused -> running' => [GameStatus::Paused, GameStatus::Running, true];
        yield 'running -> resolving' => [GameStatus::Running, GameStatus::Resolving, true];
        yield 'resolving -> completed' => [GameStatus::Resolving, GameStatus::Completed, true];

        // cancel is permitted from non-running, non-terminal states
        yield 'draft -> cancelled' => [GameStatus::Draft, GameStatus::Cancelled, true];
        yield 'published -> cancelled' => [GameStatus::Published, GameStatus::Cancelled, true];
        yield 'sales_open -> cancelled' => [GameStatus::SalesOpen, GameStatus::Cancelled, true];
        yield 'sales_closed -> cancelled' => [GameStatus::SalesClosed, GameStatus::Cancelled, true];
        yield 'paused -> cancelled' => [GameStatus::Paused, GameStatus::Cancelled, true];

        // invalid jumps
        yield 'draft -> running' => [GameStatus::Draft, GameStatus::Running, false];
        yield 'published -> completed' => [GameStatus::Published, GameStatus::Completed, false];
        yield 'sales_open -> running' => [GameStatus::SalesOpen, GameStatus::Running, false];
        yield 'running -> completed' => [GameStatus::Running, GameStatus::Completed, false];
        yield 'completed -> running' => [GameStatus::Completed, GameStatus::Running, false];
        yield 'cancelled -> draft' => [GameStatus::Cancelled, GameStatus::Draft, false];
        yield 'resolving -> cancelled' => [GameStatus::Resolving, GameStatus::Cancelled, false];
        yield 'running -> cancelled' => [GameStatus::Running, GameStatus::Cancelled, false];
    }

    #[DataProvider('transitions')]
    public function test_can_transition_to_matches_matrix(
        GameStatus $current,
        GameStatus $next,
        bool $expected,
    ): void {
        $this->assertSame($expected, $current->canTransitionTo($next));
    }

    public function test_completed_and_cancelled_are_terminal(): void
    {
        $this->assertTrue(GameStatus::Completed->isTerminal());
        $this->assertTrue(GameStatus::Cancelled->isTerminal());
    }

    public function test_non_terminal_states_report_at_least_one_next(): void
    {
        foreach (GameStatus::cases() as $status) {
            if (! $status->isTerminal()) {
                $this->assertNotSame([], $status->allowedNextStates(), "{$status->value} should have next states");
            }
        }
    }

    public function test_scheduled_is_no_longer_a_state(): void
    {
        $names = array_map(fn (GameStatus $s) => $s->value, GameStatus::cases());

        $this->assertNotContains('scheduled', $names);
    }

    public function test_scheduled_start_is_configurable_only_in_pre_running_states(): void
    {
        $configurable = array_map(
            fn (GameStatus $s) => $s->value,
            GameStatus::statesWhereScheduledStartIsConfigurable(),
        );

        $this->assertSame(
            ['published', 'sales_open', 'sales_closed'],
            $configurable,
        );
    }
}
