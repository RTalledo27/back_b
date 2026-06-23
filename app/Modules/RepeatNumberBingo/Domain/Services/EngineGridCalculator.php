<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Pure-function utilities for placing ticks on the engine's draw grid.
 *
 * The grid is the infinite sequence:
 *   startedAt + 1*interval, startedAt + 2*interval, ...
 *
 * All arithmetic uses signed integer seconds (no absolute values) so that
 * inverted or clock-skewed inputs surface immediately rather than silently
 * producing wrong grid positions.
 */
final class EngineGridCalculator
{
    /**
     * Advance the calendar after a tick has been consumed.
     * This is the value to store in next_draw_at after a successful draw.
     */
    public function advanceAfter(CarbonImmutable $consumed, int $intervalSeconds): CarbonImmutable
    {
        $this->requirePositiveInterval($intervalSeconds);

        return $consumed->addSeconds($intervalSeconds);
    }

    /**
     * Skip-to-next catch-up policy.
     *
     * Returns the first grid point strictly after $now, measured from
     * $lastConsumedAt. Used when the engine falls behind and must jump
     * past stale ticks rather than executing every missed draw.
     *
     * When $now <= $lastConsumedAt (e.g. clock skew or first tick not yet
     * due) the method returns lastConsumedAt + interval, which is always
     * a valid next tick.
     *
     * Example: lastConsumedAt=T+30, interval=30, now=T+100
     *   elapsed=70, steps=floor(70/30)+1=3, result=T+30+90=T+120 ✓
     */
    public function skipToNext(
        CarbonImmutable $lastConsumedAt,
        int $intervalSeconds,
        CarbonImmutable $now,
    ): CarbonImmutable {
        $this->requirePositiveInterval($intervalSeconds);

        $elapsed = $now->timestamp - $lastConsumedAt->timestamp;

        // When now is not yet past lastConsumedAt, clamp to the first tick.
        $steps = max(1, (int) floor($elapsed / $intervalSeconds) + 1);

        return $lastConsumedAt->addSeconds($steps * $intervalSeconds);
    }

    /**
     * Count how many whole intervals fit between $from and $to (signed).
     *
     * Used to produce the aggregated engine_ticks_skipped audit count when
     * the catch-up policy skips one or more ticks.
     *
     * Example: from=T+30, to=T+120, interval=30 → (120-30)/30 = 3 ✓
     *
     * @throws InvalidArgumentException if $to is before $from or interval <= 0.
     */
    public function countSkippedBetween(
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $intervalSeconds,
    ): int {
        $this->requirePositiveInterval($intervalSeconds);

        $diff = $to->timestamp - $from->timestamp;

        if ($diff < 0) {
            throw new InvalidArgumentException(
                "\$to ({$to->toIso8601String()}) must not be before \$from ({$from->toIso8601String()})."
            );
        }

        return (int) floor($diff / $intervalSeconds);
    }

    private function requirePositiveInterval(int $intervalSeconds): void
    {
        if ($intervalSeconds <= 0) {
            throw new InvalidArgumentException(
                "intervalSeconds must be > 0, got {$intervalSeconds}."
            );
        }
    }
}
