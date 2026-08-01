<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Exceptions;

use RuntimeException;

final class WinnerClaimNotProcessable extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function ownership(): self
    {
        return new self('winner_claim_not_owned', 'The authenticated user cannot access this winner claim.');
    }

    public static function emailNotVerified(): self
    {
        return new self('email_not_verified', 'The winner email must be verified before submitting a claim.');
    }

    public static function expired(string $claimId): self
    {
        return new self('claim_expired', "Winner claim {$claimId} has expired.");
    }

    public static function claimWindowNotStarted(string $claimId): self
    {
        return new self('claim_window_not_started', "Winner claim {$claimId} has no active claim window.");
    }

    public static function status(string $claimId, string $status): self
    {
        return new self('claim_status_not_processable', "Winner claim {$claimId} is not processable in status {$status}.");
    }

    public static function missingDocuments(string $claimId): self
    {
        return new self('identity_documents_required', "Winner claim {$claimId} has no identity documents.");
    }

    public static function selfReview(): self
    {
        return new self('reviewer_cannot_be_winner', 'The winner cannot review their own identity.');
    }

    public static function invalidConfiguration(string $message): self
    {
        return new self('winner_claim_configuration_invalid', $message);
    }
}
