<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class WinnerPayoutDestinationData
{
    /** @param array<string, scalar|null> $payload */
    public function __construct(
        public string $method,
        public array $payload,
        public string $masked,
    ) {}
}
