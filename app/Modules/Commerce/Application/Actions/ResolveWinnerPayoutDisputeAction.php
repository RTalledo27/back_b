<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\ResolveWinnerPayoutDisputeData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutDisputeStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutSettlementException;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Shared\Application\Actions\RecordOutboxEventAction;

final class ResolveWinnerPayoutDisputeAction
{
    public function __construct(
        private readonly WinnerPayoutWorkflow $workflow,
        private readonly RecordOutboxEventAction $outbox,
    ) {}

    public function executeWithinTransaction(ResolveWinnerPayoutDisputeData $data): WinnerPayoutSettlementCommandResult
    {
        $this->workflow->assertTransaction();
        $payoutId = (string) WinnerPayoutDispute::query()->whereKey($data->disputeId)->value('winner_payout_id');
        $payout = $this->workflow->lockPayout($payoutId);
        $dispute = WinnerPayoutDispute::query()->whereKey($data->disputeId)->lockForUpdate()->firstOrFail();
        if ($dispute->winner_payout_id !== $payout->id || $dispute->status !== WinnerPayoutDisputeStatus::UnderReview || $payout->status !== WinnerPayoutStatus::Disputed) {
            throw WinnerPayoutSettlementException::notEligible('resolve_requires_under_review');
        }
        if ($dispute->winner_user_id === $data->actorUserId) {
            throw WinnerPayoutSettlementException::notEligible('winner_cannot_resolve_dispute');
        }

        $nextPayoutStatus = in_array($data->resolutionCode, ['retry_required', 'corrective_action_required'], true)
            ? WinnerPayoutStatus::Failed
            : WinnerPayoutStatus::Paid;
        $now = now();
        $dispute->transitionTo(WinnerPayoutDisputeStatus::Resolved);
        $dispute->resolution_code = $data->resolutionCode;
        $dispute->resolved_by_user_id = $data->actorUserId;
        $dispute->resolved_at = $now;
        $dispute->updated_at = $now;
        $dispute->save();

        $payout->transitionTo($nextPayoutStatus);
        $payout->updated_at = $now;
        if ($nextPayoutStatus === WinnerPayoutStatus::Failed) {
            $payout->failure_reason_code = $data->reasonCode;
            $payout->failed_at = $now;
        }
        $payout->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::DisputeResolved, WinnerPayoutStatus::Disputed->value, $nextPayoutStatus->value, $data->actorUserId, 'admin', $data->resolutionCode, ['dispute_id' => (string) $dispute->id]);
        $this->outbox->execute(
            eventType: 'winner_payout_dispute_resolved',
            aggregateType: 'winner_payout',
            aggregateId: (string) $payout->id,
            deduplicationKey: 'winner_payout_dispute_resolved:'.$dispute->id,
            payload: [
                'schema_version' => 1,
                'payout_id' => (string) $payout->id,
                'dispute_id' => (string) $dispute->id,
                'resolution_code' => $data->resolutionCode,
                'occurred_at' => $now->utc()->toIso8601String(),
            ],
        );

        return new WinnerPayoutSettlementCommandResult((string) $payout->id, (string) $dispute->id, $dispute->status->value);
    }
}
