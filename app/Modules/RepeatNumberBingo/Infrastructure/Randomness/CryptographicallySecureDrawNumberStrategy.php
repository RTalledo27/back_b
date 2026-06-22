<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Infrastructure\Randomness;

use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use InvalidArgumentException;

/**
 * Production implementation of DrawNumberStrategy.
 *
 *   - Source: PHP's `random_int()`, which delegates to the OS CSPRNG
 *     (Windows: BCryptGenRandom; Linux/Mac: getrandom/arc4random).
 *   - Uniform: random_int() rejects bias by construction.
 *   - NOT a public verifiability protocol on its own — commit-reveal or a
 *     third-party verifiable random function would have to live behind a
 *     different strategy implementation when/if that requirement arises.
 */
final class CryptographicallySecureDrawNumberStrategy implements DrawNumberStrategy
{
    public const NAME = 'crypto_secure';

    public function generate(int $minimum, int $maximum): int
    {
        if ($minimum > $maximum) {
            throw new InvalidArgumentException(
                sprintf('Invalid draw range: minimum (%d) is greater than maximum (%d).', $minimum, $maximum)
            );
        }

        return random_int($minimum, $maximum);
    }

    public function name(): string
    {
        return self::NAME;
    }
}
