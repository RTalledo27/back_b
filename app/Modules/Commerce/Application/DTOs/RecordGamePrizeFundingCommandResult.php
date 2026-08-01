<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

use App\Modules\RepeatNumberBingo\Application\DTOs\RecordGamePrizeFundingResult;

final readonly class RecordGamePrizeFundingCommandResult implements CommandResult
{
    public function __construct(
        public RecordGamePrizeFundingResult $funding,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->funding->toArray();
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(RecordGamePrizeFundingResult::fromArray($payload));
    }
}
