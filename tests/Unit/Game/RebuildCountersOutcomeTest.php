<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersOutcome;
use App\Modules\RepeatNumberBingo\Application\DTOs\RebuildCountersResult;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class RebuildCountersOutcomeTest extends TestCase
{
    public function test_rebuilt_applies_transition(): void
    {
        $this->assertTrue(RebuildCountersOutcome::Rebuilt->wasTransitionApplied());
    }

    public function test_already_consistent_does_not_apply_transition(): void
    {
        $this->assertFalse(RebuildCountersOutcome::AlreadyConsistent->wasTransitionApplied());
    }

    public function test_result_dto_carries_all_metrics(): void
    {
        $at = CarbonImmutable::parse('2026-06-22T10:00:00Z');
        $r = new RebuildCountersResult(
            gameId: 'g',
            previousRows: 8,
            previousHitsTotal: 41,
            rebuiltRows: 7,
            rebuiltHitsTotal: 43,
            totalDraws: 43,
            maxSequence: 43,
            rebuiltAt: $at,
            outcome: RebuildCountersOutcome::Rebuilt,
        );
        $this->assertSame('g', $r->gameId);
        $this->assertSame(8, $r->previousRows);
        $this->assertSame(43, $r->rebuiltHitsTotal);
        $this->assertSame(43, $r->totalDraws);
        $this->assertSame(43, $r->maxSequence);
        $this->assertSame(RebuildCountersOutcome::Rebuilt, $r->outcome);
        $this->assertInstanceOf(CarbonImmutable::class, $r->rebuiltAt);
    }
}
