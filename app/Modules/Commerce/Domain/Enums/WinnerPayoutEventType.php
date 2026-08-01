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
    case ReceiptCreated = 'winner_receipt_created';
    case ReceiptConfirmed = 'winner_receipt_confirmed';
    case ReceiptWindowExpired = 'winner_receipt_window_expired';
    case DisputeOpened = 'winner_dispute_opened';
    case DisputeReviewStarted = 'winner_dispute_review_started';
    case DisputeResolved = 'winner_dispute_resolved';
    case ReconciliationRecorded = 'payout_reconciliation_recorded';
    case ReconciliationDiscrepancy = 'payout_reconciliation_discrepancy';
    case FinanciallyClosed = 'game_financially_closed';
    case ExecutionFailed = 'payout_execution_failed';
    case Cancelled = 'payout_cancelled';
    case LegacyInitialized = 'legacy_payout_initialized';
}
