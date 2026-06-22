<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use PHPUnit\Framework\TestCase;

final class GameEventTypeCountersRebuiltTest extends TestCase
{
    public function test_counters_rebuilt_case_exists_with_expected_value(): void
    {
        $this->assertTrue(defined(GameEventType::class.'::CountersRebuilt'));
        $this->assertSame('counters_rebuilt', GameEventType::CountersRebuilt->value);
    }
}
