<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryStatusTransitionTest extends TestCase
{
    /**
     * @return iterable<string, array{0: EntryStatus, 1: EntryStatus, 2: bool}>
     */
    public static function transitions(): iterable
    {
        yield 'confirmed -> winner' => [EntryStatus::Confirmed, EntryStatus::Winner, true];
        yield 'confirmed -> cancelled' => [EntryStatus::Confirmed, EntryStatus::Cancelled, true];
        yield 'confirmed -> refunded' => [EntryStatus::Confirmed, EntryStatus::Refunded, true];

        yield 'winner -> confirmed (forbidden)' => [EntryStatus::Winner, EntryStatus::Confirmed, false];
        yield 'cancelled -> confirmed (forbidden)' => [EntryStatus::Cancelled, EntryStatus::Confirmed, false];
        yield 'refunded -> confirmed (forbidden)' => [EntryStatus::Refunded, EntryStatus::Confirmed, false];
        yield 'winner -> cancelled (forbidden)' => [EntryStatus::Winner, EntryStatus::Cancelled, false];
    }

    #[DataProvider('transitions')]
    public function test_can_transition_to_matches_matrix(
        EntryStatus $current,
        EntryStatus $next,
        bool $expected,
    ): void {
        $this->assertSame($expected, $current->canTransitionTo($next));
    }

    public function test_only_confirmed_is_not_terminal(): void
    {
        $this->assertFalse(EntryStatus::Confirmed->isTerminal());
        $this->assertTrue(EntryStatus::Winner->isTerminal());
        $this->assertTrue(EntryStatus::Cancelled->isTerminal());
        $this->assertTrue(EntryStatus::Refunded->isTerminal());
    }
}
