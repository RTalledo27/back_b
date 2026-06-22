<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

use App\Modules\RepeatNumberBingo\Domain\ValueObjects\DrawCommandId;

/**
 * Input for DrawGameNumberAction. Contains data only — no strategy, no
 * HTTP request, no Eloquent state, no callbacks. The strategy is injected
 * into the Action via constructor.
 */
final readonly class DrawGameNumberData
{
    public function __construct(
        public string $gameId,
        public DrawCommandId $commandId,
        public int $actorUserId,
    ) {}
}
