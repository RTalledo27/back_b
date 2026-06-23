<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

use App\Modules\RepeatNumberBingo\Domain\ValueObjects\EngineTick;

final readonly class DispatchDueGameDrawsResult
{
    /**
     * @param  list<EngineTick>  $ticks  Ticks selected for dispatch this batch.
     * @param  int  $candidatesFound  Candidate game IDs returned by the candidate
     *                                query (bounded by dispatch_batch_size).
     *                                candidatesFound > count($ticks) when SKIP LOCKED
     *                                discards rows held by another dispatcher.
     */
    public function __construct(
        public array $ticks,
        public int $candidatesFound,
    ) {}
}
