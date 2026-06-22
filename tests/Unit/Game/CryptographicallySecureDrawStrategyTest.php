<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Modules\RepeatNumberBingo\Infrastructure\Randomness\CryptographicallySecureDrawNumberStrategy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CryptographicallySecureDrawStrategyTest extends TestCase
{
    public function test_returns_value_within_inclusive_range_over_many_samples(): void
    {
        $strategy = new CryptographicallySecureDrawNumberStrategy;
        for ($i = 0; $i < 200; $i++) {
            $value = $strategy->generate(3, 9);
            $this->assertGreaterThanOrEqual(3, $value);
            $this->assertLessThanOrEqual(9, $value);
        }
    }

    public function test_min_equal_max_yields_that_value(): void
    {
        $strategy = new CryptographicallySecureDrawNumberStrategy;
        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(42, $strategy->generate(42, 42));
        }
    }

    public function test_inverted_range_is_rejected(): void
    {
        $strategy = new CryptographicallySecureDrawNumberStrategy;
        $this->expectException(InvalidArgumentException::class);
        $strategy->generate(10, 1);
    }

    public function test_name_is_stable_crypto_secure(): void
    {
        $strategy = new CryptographicallySecureDrawNumberStrategy;
        $this->assertSame('crypto_secure', $strategy->name());
        $this->assertSame('crypto_secure', CryptographicallySecureDrawNumberStrategy::NAME);
    }
}
