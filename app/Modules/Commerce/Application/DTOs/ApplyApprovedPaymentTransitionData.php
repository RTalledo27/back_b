<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

use InvalidArgumentException;

final readonly class ApplyApprovedPaymentTransitionData
{
    public function __construct(
        public string $paymentId,
        public ?int $reviewerUserId = null,
        public ?string $notes = null,
        public string $origin = 'manual',
    ) {
        if (! in_array($this->origin, ['manual', 'gateway'], true)) {
            throw new InvalidArgumentException('Unsupported payment approval origin.');
        }
    }

    public function isGateway(): bool
    {
        return $this->origin === 'gateway';
    }
}
