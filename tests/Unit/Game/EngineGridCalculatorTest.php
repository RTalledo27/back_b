<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Domain\Services\EngineGridCalculator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EngineGridCalculatorTest extends TestCase
{
    private EngineGridCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new EngineGridCalculator;
    }

    // -------------------------------------------------------------------------
    // advanceAfter
    // -------------------------------------------------------------------------

    public function test_advance_after_adds_interval(): void
    {
        $consumed = CarbonImmutable::parse('2026-06-22 10:00:30');
        $next = $this->calculator->advanceAfter($consumed, 30);

        $this->assertSame(
            CarbonImmutable::parse('2026-06-22 10:01:00')->timestamp,
            $next->timestamp,
        );
    }

    public function test_advance_after_does_not_mutate_input(): void
    {
        $consumed = CarbonImmutable::parse('2026-06-22 10:00:30');
        $this->calculator->advanceAfter($consumed, 30);

        $this->assertSame(
            CarbonImmutable::parse('2026-06-22 10:00:30')->timestamp,
            $consumed->timestamp,
        );
    }

    public function test_advance_after_rejects_zero_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->advanceAfter(CarbonImmutable::now(), 0);
    }

    public function test_advance_after_rejects_negative_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->advanceAfter(CarbonImmutable::now(), -10);
    }

    // -------------------------------------------------------------------------
    // skipToNext
    // -------------------------------------------------------------------------

    public function test_skip_to_next_returns_next_grid_point_after_now(): void
    {
        // lastConsumedAt=T+30, interval=30, now=T+100 → steps=3, result=T+120
        $base = CarbonImmutable::parse('2026-06-22 10:00:00');
        $next = $this->calculator->skipToNext($base->addSeconds(30), 30, $base->addSeconds(100));

        $this->assertSame($base->addSeconds(120)->timestamp, $next->timestamp);
    }

    public function test_skip_to_next_when_now_equals_exact_grid_point_advances_one_more(): void
    {
        // lastConsumedAt=T+30, now=T+60 (on grid) → result=T+90
        $base = CarbonImmutable::parse('2026-06-22 10:00:00');
        $next = $this->calculator->skipToNext($base->addSeconds(30), 30, $base->addSeconds(60));

        $this->assertSame($base->addSeconds(90)->timestamp, $next->timestamp);
    }

    public function test_skip_to_next_when_now_is_barely_past_last_consumed(): void
    {
        // lastConsumedAt=T+30, now=T+35 → steps=1, result=T+60
        $base = CarbonImmutable::parse('2026-06-22 10:00:00');
        $next = $this->calculator->skipToNext($base->addSeconds(30), 30, $base->addSeconds(35));

        $this->assertSame($base->addSeconds(60)->timestamp, $next->timestamp);
    }

    public function test_skip_to_next_when_now_is_before_last_consumed_returns_next_tick(): void
    {
        // now < lastConsumedAt (e.g. clock skew) → clamp to lastConsumedAt + interval
        $base = CarbonImmutable::parse('2026-06-22 10:00:00');
        $lastConsumed = $base->addSeconds(30);
        $now = $base->addSeconds(20); // now < lastConsumedAt

        $next = $this->calculator->skipToNext($lastConsumed, 30, $now);

        $this->assertSame($base->addSeconds(60)->timestamp, $next->timestamp);
    }

    public function test_skip_to_next_result_is_always_strictly_after_now(): void
    {
        $base = CarbonImmutable::parse('2026-06-22 10:00:00');
        $lastConsumed = $base->addSeconds(30);

        for ($offset = 1; $offset <= 120; $offset++) {
            $now = $base->addSeconds($offset);
            $next = $this->calculator->skipToNext($lastConsumed, 30, $now);
            $this->assertGreaterThan($now->timestamp, $next->timestamp,
                "skipToNext must be strictly after now (offset={$offset})");
        }
    }

    public function test_skip_to_next_rejects_zero_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->skipToNext(CarbonImmutable::now(), 0, CarbonImmutable::now());
    }

    public function test_skip_to_next_rejects_negative_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->skipToNext(CarbonImmutable::now(), -5, CarbonImmutable::now());
    }

    // -------------------------------------------------------------------------
    // countSkippedBetween
    // -------------------------------------------------------------------------

    public function test_count_skipped_three_intervals(): void
    {
        $base = CarbonImmutable::parse('2026-06-22 10:00:00');

        $this->assertSame(3, $this->calculator->countSkippedBetween(
            $base->addSeconds(30),
            $base->addSeconds(120),
            30,
        ));
    }

    public function test_count_skipped_zero_when_from_equals_to(): void
    {
        $at = CarbonImmutable::parse('2026-06-22 10:00:30');

        $this->assertSame(0, $this->calculator->countSkippedBetween($at, $at, 30));
    }

    public function test_count_skipped_one_interval(): void
    {
        $base = CarbonImmutable::parse('2026-06-22 10:00:00');

        $this->assertSame(1, $this->calculator->countSkippedBetween(
            $base->addSeconds(30),
            $base->addSeconds(60),
            30,
        ));
    }

    public function test_count_skipped_rounds_down_for_partial_interval(): void
    {
        // diff=50, interval=30 → floor(50/30)=1
        $base = CarbonImmutable::parse('2026-06-22 10:00:00');

        $this->assertSame(1, $this->calculator->countSkippedBetween(
            $base->addSeconds(30),
            $base->addSeconds(80),
            30,
        ));
    }

    public function test_count_skipped_rejects_to_before_from(): void
    {
        $base = CarbonImmutable::parse('2026-06-22 10:00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->calculator->countSkippedBetween(
            $base->addSeconds(60),
            $base->addSeconds(30), // to < from
            30,
        );
    }

    public function test_count_skipped_rejects_zero_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->countSkippedBetween(CarbonImmutable::now(), CarbonImmutable::now(), 0);
    }

    public function test_count_skipped_rejects_negative_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->countSkippedBetween(CarbonImmutable::now(), CarbonImmutable::now(), -1);
    }

    // -------------------------------------------------------------------------
    // skipToNext + countSkippedBetween integration
    // -------------------------------------------------------------------------

    public function test_skip_and_count_are_consistent(): void
    {
        $base = CarbonImmutable::parse('2026-06-22 10:00:00');
        $lastConsumed = $base->addSeconds(30);
        $now = $base->addSeconds(100);

        $newNext = $this->calculator->skipToNext($lastConsumed, 30, $now);
        $skipped = $this->calculator->countSkippedBetween($lastConsumed, $newNext, 30);

        $this->assertSame(3, $skipped);
    }
}
