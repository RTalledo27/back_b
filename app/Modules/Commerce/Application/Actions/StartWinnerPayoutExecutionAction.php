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
use App\Modules\Commerce\Domain\Models\WinnerPayoutDestination;
use App\Modules\Commerce\Domain\Models\WinnerPayoutExecutionAttempt;

final class StartWinnerPayoutExecutionAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeWithinTransaction(WinnerPayoutTransitionData $data): WinnerPayoutCommandResult
    {
        $this->workflow->assertTransaction();
        $payout = $this->workflow->lockPayout($data->payoutId);
        if (! in_array($payout->status, [WinnerPayoutStatus::Approved, WinnerPayoutStatus::Failed], true)) {
            throw WinnerPayoutWorkflowException::notEligible('processing_requires_approval_or_failed');
        }
        if ((int) $payout->created_by_user_id === $data->actorUserId) {
            throw WinnerPayoutWorkflowException::actorSeparation();
        }
        if ((int) $payout->user_id === $data->actorUserId) {
            throw WinnerPayoutWorkflowException::winnerActorSeparation();
        }
        if ($payout->current_destination_id === null) {
            throw WinnerPayoutWorkflowException::destinationRequired();
        }
        if (! WinnerPayoutDestination::query()
            ->whereKey($payout->current_destination_id)
            ->where('winner_payout_id', $payout->id)
            ->exists()) {
            throw WinnerPayoutWorkflowException::destinationRequired();
        }
        if (WinnerPayoutExecutionAttempt::query()->where('winner_payout_id', $payout->id)->where('status', WinnerPayoutExecutionAttemptStatus::Processing)->lockForUpdate()->exists()) {
            throw WinnerPayoutWorkflowException::processingAttemptAlreadyExists();
        }

        $from = $payout->status->value;
        $attemptNumber = ((int) WinnerPayoutExecutionAttempt::query()->where('winner_payout_id', $payout->id)->max('attempt_number')) + 1;
        $attempt = WinnerPayoutExecutionAttempt::create([
            'winner_payout_id' => $payout->id,
            'attempt_number' => $attemptNumber,
            'status' => WinnerPayoutExecutionAttemptStatus::Processing,
            'destination_id' => $payout->current_destination_id,
            'started_by_user_id' => $data->actorUserId,
            'idempotency_key_hash' => $data->idempotencyKeyHash,
            'request_fingerprint' => $data->requestFingerprint,
            'started_at' => now(),
            'created_at' => now(),
        ]);

        $payout->transitionTo(WinnerPayoutStatus::Processing);
        $payout->current_execution_attempt_id = $attempt->id;
        $payout->processing_at = now();
        $payout->updated_at = now();
        $payout->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::ProcessingStarted, $from, WinnerPayoutStatus::Processing->value, $data->actorUserId, 'admin', null, ['attempt_number' => $attemptNumber]);

        return $this->workflow->result($payout, true, (string) $attempt->id);
    }
}
