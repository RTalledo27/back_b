<?php

declare(strict_types=1);

namespace Tests\Unit\Money;

use App\Modules\Shared\Domain\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_constructs_with_positive_amount_and_iso_currency(): void
    {
        $money = new Money(1500, 'PEN');

        $this->assertSame(1500, $money->amountInCents);
        $this->assertSame('PEN', $money->currency);
    }

    public function test_rejects_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Money(-1, 'PEN');
    }

    public function test_rejects_lowercase_or_non_iso_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Money(100, 'pen');
    }

    public function test_of_normalizes_currency_to_uppercase(): void
    {
        $this->assertSame('USD', Money::of(50, 'usd')->currency);
    }

    public function test_addition_returns_new_instance_with_summed_amount(): void
    {
        $sum = (new Money(100, 'PEN'))->add(new Money(250, 'PEN'));

        $this->assertSame(350, $sum->amountInCents);
        $this->assertSame('PEN', $sum->currency);
    }

    public function test_addition_rejects_different_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Money(100, 'PEN'))->add(new Money(100, 'USD'));
    }

    public function test_subtraction_rejects_results_below_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Money(100, 'PEN'))->subtract(new Money(200, 'PEN'));
    }

    public function test_equals_compares_amount_and_currency(): void
    {
        $this->assertTrue((new Money(100, 'PEN'))->equals(new Money(100, 'PEN')));
        $this->assertFalse((new Money(100, 'PEN'))->equals(new Money(100, 'USD')));
        $this->assertFalse((new Money(100, 'PEN'))->equals(new Money(200, 'PEN')));
    }

    public function test_zero_and_positive_helpers(): void
    {
        $this->assertTrue((new Money(0, 'PEN'))->isZero());
        $this->assertFalse((new Money(1, 'PEN'))->isZero());
        $this->assertTrue((new Money(1, 'PEN'))->isPositive());
        $this->assertFalse((new Money(0, 'PEN'))->isPositive());
    }
}
