<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutTransitionData;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutWorkflowException;

final class RejectWinnerPayoutApprovalAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeWithinTransaction(WinnerPayoutTransitionData $data): WinnerPayoutCommandResult
    {
        $this->workflow->assertTransaction();
        $payout = $this->workflow->lockPayout($data->payoutId);
        if ($payout->status !== WinnerPayoutStatus::AwaitingApproval) {
            throw WinnerPayoutWorkflowException::notEligible('reject_requires_awaiting_approval');
        }
        if ((int) $payout->created_by_user_id === $data->actorUserId) {
            throw WinnerPayoutWorkflowException::actorSeparation();
        }
        if ($data->reasonCode === null || trim($data->reasonCode) === '') {
            throw WinnerPayoutWorkflowException::notEligible('rejection_reason_required');
        }

        $payout->transitionTo(WinnerPayoutStatus::Draft);
        $payout->approval_rejection_reason_code = $data->reasonCode;
        $payout->updated_at = now();
        $payout->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::ApprovalRejected, WinnerPayoutStatus::AwaitingApproval->value, WinnerPayoutStatus::Draft->value, $data->actorUserId, 'admin', $data->reasonCode);

        return $this->workflow->result($payout);
    }
}
