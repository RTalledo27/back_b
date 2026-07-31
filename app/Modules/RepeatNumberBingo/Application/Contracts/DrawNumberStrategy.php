<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Contracts;

/**
 * Port used by the engine to obtain the next drawn number. The production
 * implementation is cryptographically secure; tests inject a deterministic
 * implementation. The engine knows nothing about the source of randomness.
 */
interface DrawNumberStrategy
{
    /**
     * @param  int  $minimum  inclusive lower bound (>= 1)
     * @param  int  $maximum  inclusive upper bound (>= $minimum)
     * @param  list<int>  $excluded  numbers that are no longer eligible
     */
    public function generate(int $minimum, int $maximum, array $excluded = []): int;

    /**
     * Stable identifier persisted into game_draws.strategy.
     */
    public function name(): string;
}
