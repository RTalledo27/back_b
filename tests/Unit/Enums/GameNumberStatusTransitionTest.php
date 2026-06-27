<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GameNumberStatusTransitionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: GameNumberStatus, 1: GameNumberStatus, 2: bool}>
     */
    public static function transitions(): iterable
    {
        yield 'available -> reserved' => [GameNumberStatus::Available, GameNumberStatus::Reserved, true];
        yield 'reserved -> available' => [GameNumberStatus::Reserved, GameNumberStatus::Available, true];
        yield 'reserved -> sold' => [GameNumberStatus::Reserved, GameNumberStatus::Sold, true];

        yield 'available -> sold (forbidden, must reserve first)' => [GameNumberStatus::Available, GameNumberStatus::Sold, false];
        // Sold -> Available is allowed: admin refund returns numbers to the pool.
        yield 'sold -> available (admin refund)' => [GameNumberStatus::Sold, GameNumberStatus::Available, true];
        yield 'sold -> reserved (forbidden)' => [GameNumberStatus::Sold, GameNumberStatus::Reserved, false];
    }

    #[DataProvider('transitions')]
    public function test_can_transition_to_matches_matrix(
        GameNumberStatus $current,
        GameNumberStatus $next,
        bool $expected,
    ): void {
        $this->assertSame($expected, $current->canTransitionTo($next));
    }

    public function test_sold_is_not_terminal_because_refund_can_reclaim_numbers(): void
    {
        $this->assertFalse(GameNumberStatus::Sold->isTerminal());
        $this->assertFalse(GameNumberStatus::Available->isTerminal());
        $this->assertFalse(GameNumberStatus::Reserved->isTerminal());
    }
}
