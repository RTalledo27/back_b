<?php

declare(strict_types=1);

namespace Tests\Unit\BingoNumberRange;

use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameConfiguration;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\BingoNumberRange;
use PHPUnit\Framework\TestCase;

final class BingoNumberRangeTest extends TestCase
{
    public function test_default_initial_configuration_one_to_thirty_with_five_hits(): void
    {
        $range = new BingoNumberRange(1, 30, 5);

        $this->assertSame(30, $range->count());
        $this->assertSame(range(1, 30), $range->toList());
        $this->assertTrue($range->contains(15));
        $this->assertFalse($range->contains(31));
    }

    public function test_rejects_minimum_below_one(): void
    {
        $this->expectException(InvalidGameConfiguration::class);
        new BingoNumberRange(0, 30, 5);
    }

    public function test_rejects_maximum_equal_to_minimum(): void
    {
        $this->expectException(InvalidGameConfiguration::class);
        new BingoNumberRange(10, 10, 5);
    }

    public function test_rejects_maximum_below_minimum(): void
    {
        $this->expectException(InvalidGameConfiguration::class);
        new BingoNumberRange(10, 5, 5);
    }

    public function test_rejects_hits_required_below_two(): void
    {
        $this->expectException(InvalidGameConfiguration::class);
        new BingoNumberRange(1, 30, 1);
    }
}
