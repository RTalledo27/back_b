<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class EngineTickTest extends TestCase
{
    public function test_stores_all_fields_immutably(): void
    {
        $gameId = '550e8400-e29b-41d4-a716-446655440000';
        $scheduledAt = CarbonImmutable::parse('2026-06-22 10:00:00');
        $commandId = new DrawCommandId('abcdef12-1234-1234-1234-1234567890ab');

        $tick = new EngineTick($gameId, $scheduledAt, $commandId);

        $this->assertSame($gameId, $tick->gameId);
        $this->assertSame($scheduledAt->timestamp, $tick->scheduledAt->timestamp);
        $this->assertTrue($commandId->equals($tick->commandId));
    }

    public function test_scheduled_at_is_carbon_immutable(): void
    {
        $tick = new EngineTick(
            '550e8400-e29b-41d4-a716-446655440000',
            CarbonImmutable::now(),
            new DrawCommandId('abcdef12-1234-1234-1234-1234567890ab'),
        );

        $this->assertInstanceOf(CarbonImmutable::class, $tick->scheduledAt);
    }
}
