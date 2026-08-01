<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

use App\Modules\RepeatNumberBingo\Application\DTOs\WinnerClaimReviewResult;

final readonly class WinnerClaimReviewCommandResult implements CommandResult
{
    public function __construct(private WinnerClaimReviewResult $result) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->result->toArray();
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(WinnerClaimReviewResult::fromArray($payload));
    }
}
