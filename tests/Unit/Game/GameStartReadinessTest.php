<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameNotReadyForStart;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameStartReadiness;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class GameStartReadinessTest extends TestCase
{
    public function test_holds_count_and_immutable_timestamp(): void
    {
        $at = CarbonImmutable::parse('2026-06-22T08:00:00Z');
        $r = new GameStartReadiness(confirmedEntriesCount: 4, verifiedAt: $at);
        $this->assertSame(4, $r->confirmedEntriesCount);
        $this->assertTrue($r->verifiedAt->equalTo($at));
        $this->assertInstanceOf(CarbonImmutable::class, $r->verifiedAt);
    }

    public function test_not_ready_carries_reasons_list(): void
    {
        $e = GameNotReadyForStart::withReasons(['has_pending_orders', 'has_reserved_numbers']);
        $this->assertSame(['has_pending_orders', 'has_reserved_numbers'], $e->reasons);
        $this->assertStringContainsString('has_pending_orders', $e->getMessage());
    }

    public function test_not_ready_rejects_empty_reason_list(): void
    {
        $this->expectException(\LogicException::class);
        GameNotReadyForStart::withReasons([]);
    }
}
