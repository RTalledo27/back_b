<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use LogicException;

/**
 * Test-only strategy that yields a fixed sequence of integers. Bound into
 * the container in feature/integration tests when the production CSPRNG
 * would make assertions unstable. Lives outside `app/` on purpose; an
 * architectural test rejects any production code importing it.
 */
final class DeterministicDrawNumberStrategy implements DrawNumberStrategy
{
    /**
     * @var list<int>
     */
    private array $remaining;

    /**
     * @param  list<int>  $sequence  values yielded by generate(), one per call
     */
    public function __construct(array $sequence)
    {
        $this->remaining = array_values($sequence);
    }

    public function generate(int $minimum, int $maximum): int
    {
        if ($this->remaining === []) {
            throw new LogicException('DeterministicDrawNumberStrategy exhausted.');
        }

        $next = array_shift($this->remaining);
        if ($next < $minimum || $next > $maximum) {
            throw new LogicException(sprintf(
                'Deterministic value %d outside the requested [%d, %d] range.',
                $next, $minimum, $maximum,
            ));
        }

        return $next;
    }

    public function name(): string
    {
        return 'deterministic_test';
    }
}
