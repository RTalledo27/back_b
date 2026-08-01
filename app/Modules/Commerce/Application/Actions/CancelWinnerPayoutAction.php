<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutTransitionData;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutWorkflowException;

final class CancelWinnerPayoutAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeWithinTransaction(WinnerPayoutTransitionData $data): WinnerPayoutCommandResult
    {
        $this->workflow->assertTransaction();
        $payout = $this->workflow->lockPayout($data->payoutId);
        if (! in_array($payout->status, [WinnerPayoutStatus::Draft, WinnerPayoutStatus::AwaitingApproval, WinnerPayoutStatus::Approved, WinnerPayoutStatus::Failed], true)) {
            throw WinnerPayoutWorkflowException::notEligible('cancel_not_allowed');
        }
        if ($data->reasonCode === null || trim($data->reasonCode) === '') {
            throw WinnerPayoutWorkflowException::notEligible('cancellation_reason_required');
        }

        $from = $payout->status->value;
        $payout->transitionTo(WinnerPayoutStatus::Cancelled);
        $payout->cancellation_reason_code = $data->reasonCode;
        $payout->cancelled_at = now();
        $payout->updated_at = now();
        $payout->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::Cancelled, $from, WinnerPayoutStatus::Cancelled->value, $data->actorUserId, 'admin', $data->reasonCode);

        return $this->workflow->result($payout);
    }
}
