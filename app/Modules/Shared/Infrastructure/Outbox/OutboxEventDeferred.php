<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Outbox;

use RuntimeException;

final class OutboxEventDeferred extends RuntimeException
{
    private function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Outbox event deferred until an ambiguous delivery claim expires.');
    }

    public static function forSeconds(int $seconds): self
    {
        return new self(max(1, $seconds));
    }
}
