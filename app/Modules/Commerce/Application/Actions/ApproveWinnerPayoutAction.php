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

final class ApproveWinnerPayoutAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeWithinTransaction(WinnerPayoutTransitionData $data): WinnerPayoutCommandResult
    {
        $this->workflow->assertTransaction();
        $payout = $this->workflow->lockPayout($data->payoutId);
        if ($payout->status !== WinnerPayoutStatus::AwaitingApproval) {
            throw WinnerPayoutWorkflowException::notEligible('approve_requires_awaiting_approval');
        }
        if ((int) $payout->created_by_user_id === $data->actorUserId) {
            throw WinnerPayoutWorkflowException::actorSeparation();
        }
        if ((int) $payout->user_id === $data->actorUserId) {
            throw WinnerPayoutWorkflowException::winnerActorSeparation();
        }
        if ($payout->current_destination_id === null || ! WinnerPayoutDestination::query()
            ->whereKey($payout->current_destination_id)
            ->where('winner_payout_id', $payout->id)
            ->exists()) {
            throw WinnerPayoutWorkflowException::destinationRequired();
        }

        $payout->transitionTo(WinnerPayoutStatus::Approved);
        $payout->approved_by_user_id = $data->actorUserId;
        $payout->approved_at = now();
        $payout->updated_at = now();
        $payout->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::Approved, WinnerPayoutStatus::AwaitingApproval->value, WinnerPayoutStatus::Approved->value, $data->actorUserId, 'admin');

        return $this->workflow->result($payout);
    }
}
