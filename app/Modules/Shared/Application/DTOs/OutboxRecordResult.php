<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\DTOs;

final class OutboxRecordResult
{
    public function __construct(
        public readonly bool $inserted,
        public readonly ?string $outboxEventId,
    ) {}
}
