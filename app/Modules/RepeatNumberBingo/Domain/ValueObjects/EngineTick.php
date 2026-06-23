<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * An immutable record of one scheduled draw opportunity.
 *
 * commandId is a deterministic UUID v5 derived from (namespace, gameId, scheduledAt)
 * so that replaying the same tick always produces the same DrawCommand row,
 * and the draw_commands(game_id, command_id) UNIQUE constraint guarantees
 * exactly-once execution even if the Job is delivered more than once.
 */
final readonly class EngineTick
{
    public function __construct(
        public string $gameId,
        public CarbonImmutable $scheduledAt,
        public DrawCommandId $commandId,
    ) {}
}
