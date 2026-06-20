<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class Money
{
    public function __construct(
        public int $amountInCents,
        public string $currency,
    ) {
        if ($amountInCents < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException(
                'Currency must be a 3-letter uppercase ISO code, got: '.$currency
            );
        }
    }

    public static function of(int $amountInCents, string $currency): self
    {
        return new self($amountInCents, mb_strtoupper($currency));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountInCents + $other->amountInCents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountInCents - $other->amountInCents, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amountInCents === $other->amountInCents
            && $this->currency === $other->currency;
    }

    public function isZero(): bool
    {
        return $this->amountInCents === 0;
    }

    public function isPositive(): bool
    {
        return $this->amountInCents > 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot operate on different currencies: {$this->currency} vs {$other->currency}"
            );
        }
    }
}
