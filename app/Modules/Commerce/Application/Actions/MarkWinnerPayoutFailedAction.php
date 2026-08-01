<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutTransitionData;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutExecutionAttemptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutWorkflowException;
use App\Modules\Commerce\Domain\Models\WinnerPayoutExecutionAttempt;

final class MarkWinnerPayoutFailedAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeWithinTransaction(WinnerPayoutTransitionData $data): WinnerPayoutCommandResult
    {
        $this->workflow->assertTransaction();
        $payout = $this->workflow->lockPayout($data->payoutId);
        if ($payout->status !== WinnerPayoutStatus::Processing) {
            throw WinnerPayoutWorkflowException::notEligible('failed_requires_processing');
        }
        if ($data->reasonCode === null || trim($data->reasonCode) === '') {
            throw WinnerPayoutWorkflowException::notEligible('failure_reason_required');
        }
        $attempt = WinnerPayoutExecutionAttempt::query()->whereKey($payout->current_execution_attempt_id)->lockForUpdate()->first();
        if ($attempt === null || $attempt->status !== WinnerPayoutExecutionAttemptStatus::Processing) {
            throw WinnerPayoutWorkflowException::processingAttemptRequired();
        }

        $now = now();
        $attempt->transitionTo(WinnerPayoutExecutionAttemptStatus::Failed);
        $attempt->failure_reason_code = $data->reasonCode;
        $attempt->failed_at = $now;
        $attempt->save();

        $payout->transitionTo(WinnerPayoutStatus::Failed);
        $payout->failure_reason_code = $data->reasonCode;
        $payout->failed_at = $now;
        $payout->updated_at = $now;
        $payout->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::ExecutionFailed, WinnerPayoutStatus::Processing->value, WinnerPayoutStatus::Failed->value, $data->actorUserId, 'admin', $data->reasonCode, ['attempt_number' => $attempt->attempt_number]);

        return $this->workflow->result($payout, true, (string) $attempt->id);
    }
}
