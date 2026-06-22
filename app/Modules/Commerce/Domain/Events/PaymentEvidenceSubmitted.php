<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched by SubmitPaymentEvidenceOrchestrator AFTER the business
 * transaction commits successfully. Not implementing
 * ShouldDispatchAfterCommit on purpose: the orchestrator is in charge of
 * post-commit timing AND of swallowing listener errors so they cannot
 * trigger false compensation of an already-persisted result.
 */
final class PaymentEvidenceSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly string $orderId,
        public readonly string $paymentId,
        public readonly string $documentId,
        public readonly int $userId,
    ) {}
}
