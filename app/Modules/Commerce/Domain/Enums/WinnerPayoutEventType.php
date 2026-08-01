<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Enums;

enum WinnerPayoutEventType: string
{
    case PayoutCreated = 'payout_created';
    case DestinationAdded = 'payout_destination_added';
    case SubmittedForApproval = 'payout_submitted_for_approval';
    case ApprovalRejected = 'payout_approval_rejected';
    case Approved = 'payout_approved';
    case ProcessingStarted = 'payout_processing_started';
    case ExecutionRecorded = 'payout_execution_recorded';
    case ExecutionFailed = 'payout_execution_failed';
    case Cancelled = 'payout_cancelled';
    case LegacyInitialized = 'legacy_payout_initialized';
}
