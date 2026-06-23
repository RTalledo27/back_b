<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameActionActor;

final readonly class ResumeGameData
{
    public function __construct(
        public string $gameId,
        public GameActionActor $actor,
    ) {}
}
