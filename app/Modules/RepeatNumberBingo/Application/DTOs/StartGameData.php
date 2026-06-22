<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

final readonly class StartGameData
{
    public function __construct(
        public string $gameId,
        public int $actorUserId,
    ) {}
}
