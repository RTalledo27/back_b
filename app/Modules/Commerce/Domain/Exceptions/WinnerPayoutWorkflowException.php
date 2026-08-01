<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class WinnerPayoutWorkflowException extends DomainException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function legacyWriteDisabled(): self
    {
        return new self(
            'The historical winner payout endpoint cannot create new payouts.',
            'legacy_write_disabled',
        );
    }

    public static function notEligible(string $reason): self
    {
        return new self('Winner payout is not eligible for the requested operation.', $reason);
    }

    public static function actorSeparation(): self
    {
        return new self('The payout requires a different administrator for this operation.', 'actor_separation');
    }

    public static function winnerActorSeparation(): self
    {
        return new self('The winner cannot approve, execute, or confirm their own payout.', 'winner_actor_separation');
    }

    public static function destinationRequired(): self
    {
        return new self('A valid payout destination is required.', 'destination_required');
    }

    public static function executionEvidenceRequired(): self
    {
        return new self('A private execution proof is required before marking the payout as paid.', 'execution_evidence_required');
    }

    public static function processingAttemptRequired(): self
    {
        return new self('A processing execution attempt is required.', 'processing_attempt_required');
    }

    public static function processingAttemptAlreadyExists(): self
    {
        return new self('The payout already has a processing execution attempt.', 'processing_attempt_exists');
    }

    public static function legacyRecord(): self
    {
        return new self('Historical payout records cannot enter the new lifecycle.', 'legacy_registered');
    }
}
