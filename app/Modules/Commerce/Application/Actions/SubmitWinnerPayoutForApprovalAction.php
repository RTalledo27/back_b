<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutTransitionData;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutWorkflowException;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDestination;

final class SubmitWinnerPayoutForApprovalAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeWithinTransaction(WinnerPayoutTransitionData $data): WinnerPayoutCommandResult
    {
        $this->workflow->assertTransaction();
        $payout = $this->workflow->lockPayout($data->payoutId);
        if ($payout->status !== WinnerPayoutStatus::Draft) {
            throw WinnerPayoutWorkflowException::notEligible('submit_requires_draft');
        }
        if ($payout->current_destination_id === null || ! WinnerPayoutDestination::query()
            ->whereKey($payout->current_destination_id)
            ->where('winner_payout_id', $payout->id)
            ->exists()) {
            throw WinnerPayoutWorkflowException::destinationRequired();
        }

        $payout->transitionTo(WinnerPayoutStatus::AwaitingApproval);
        $payout->submitted_by_user_id = $data->actorUserId;
        $payout->submitted_at = now();
        $payout->updated_at = now();
        $payout->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::SubmittedForApproval, WinnerPayoutStatus::Draft->value, WinnerPayoutStatus::AwaitingApproval->value, $data->actorUserId, 'admin');

        return $this->workflow->result($payout);
    }
}
