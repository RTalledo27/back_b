<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

final readonly class ReviewWinnerClaimData
{
    public function __construct(
        public string $claimId,
        public int $reviewerUserId,
        public ?string $reasonCode = null,
    ) {}
}
