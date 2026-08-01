<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class WinnerPayoutSettlementException extends DomainException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notEligible(string $reason): self
    {
        return new self('The winner payout settlement operation is not eligible.', $reason);
    }

    public static function ownership(): self
    {
        return new self('The winner payout settlement belongs to another user.', 'ownership');
    }

    public static function activeDispute(): self
    {
        return new self('The winner payout already has an active dispute.', 'active_dispute');
    }

    public static function duplicateDispute(): self
    {
        return new self('The winner payout already has an open or under-review dispute.', 'duplicate_dispute');
    }

    public static function unsafeDescription(): self
    {
        return new self('The dispute description cannot contain financial credentials or complete account data.', 'unsafe_description');
    }

    public static function reconciliationMismatch(): self
    {
        return new self('The reconciliation does not match the current paid execution attempt.', 'attempt_mismatch');
    }

    public static function closureBlocked(string $reason): self
    {
        return new self('The game cannot be financially closed yet.', $reason);
    }
}
