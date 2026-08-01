<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use RuntimeException;

final class InvalidWinnerClaimTransition extends RuntimeException
{
    public function __construct(
        public readonly WinnerClaimStatus $from,
        public readonly WinnerClaimStatus $to,
    ) {
        parent::__construct("Winner claim cannot transition from {$from->value} to {$to->value}.");
    }
}
