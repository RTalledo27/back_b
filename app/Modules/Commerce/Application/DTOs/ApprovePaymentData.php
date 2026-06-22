<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\DTOs;

final readonly class ApprovePaymentData
{
    public function __construct(
        public string $paymentId,
        public int $reviewerUserId,
        public ?string $notes = null,
    ) {}
}
