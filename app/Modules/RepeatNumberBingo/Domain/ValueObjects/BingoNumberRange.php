<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\ValueObjects;

use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameConfiguration;

final readonly class BingoNumberRange
{
    public function __construct(
        public int $min,
        public int $max,
        public int $hitsRequired,
    ) {
        if ($min < 1) {
            throw new InvalidGameConfiguration('Minimum number must be at least 1.');
        }

        if ($max <= $min) {
            throw new InvalidGameConfiguration('Maximum number must be greater than minimum.');
        }

        if ($hitsRequired < 2) {
            throw new InvalidGameConfiguration('Hits required must be at least 2.');
        }
    }

    /**
     * @return list<int>
     */
    public function toList(): array
    {
        return range($this->min, $this->max);
    }

    public function count(): int
    {
        return $this->max - $this->min + 1;
    }

    public function contains(int $number): bool
    {
        return $number >= $this->min && $number <= $this->max;
    }
}
