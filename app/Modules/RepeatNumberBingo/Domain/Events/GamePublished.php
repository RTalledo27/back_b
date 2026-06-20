<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class GamePublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $gameId,
    ) {}
}
