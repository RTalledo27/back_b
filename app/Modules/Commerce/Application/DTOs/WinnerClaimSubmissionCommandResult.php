<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

use App\Modules\RepeatNumberBingo\Application\DTOs\WinnerClaimSubmissionResult;

final readonly class WinnerClaimSubmissionCommandResult implements CommandResult
{
    public function __construct(private WinnerClaimSubmissionResult $result) {}

    public function toSubmissionResult(): WinnerClaimSubmissionResult
    {
        return $this->result;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->result->toArray();
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(WinnerClaimSubmissionResult::fromArray($payload));
    }
}
