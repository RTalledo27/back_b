<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\ReconcileWinnerPayoutData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutDisputeStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutExecutionAttemptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReconciliationStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutSettlementException;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Domain\Models\WinnerPayoutExecutionAttempt;
use App\Modules\Commerce\Domain\Models\WinnerPayoutReconciliation;
use App\Modules\Shared\Application\Actions\RecordOutboxEventAction;

final class ReconcileWinnerPayoutAction
{
    public function __construct(
        private readonly WinnerPayoutWorkflow $workflow,
        private readonly RecordOutboxEventAction $outbox,
    ) {}

    public function executeWithinTransaction(ReconcileWinnerPayoutData $data): WinnerPayoutSettlementCommandResult
    {
        $this->workflow->assertTransaction();
        $payout = $this->workflow->lockPayout($data->payoutId);
        if (! in_array($payout->status, [WinnerPayoutStatus::Paid, WinnerPayoutStatus::Disputed], true)) {
            throw WinnerPayoutSettlementException::notEligible('reconciliation_requires_paid_or_disputed');
        }

        $attempt = WinnerPayoutExecutionAttempt::query()
            ->whereKey($payout->current_execution_attempt_id)
            ->where('winner_payout_id', $payout->id)
            ->lockForUpdate()
            ->first();
        if ($attempt === null || $attempt->status !== WinnerPayoutExecutionAttemptStatus::Paid) {
            throw WinnerPayoutSettlementException::reconciliationMismatch();
        }
        if (! $attempt->documents()->where('document_type', 'execution_proof')->exists()) {
            throw WinnerPayoutSettlementException::notEligible('execution_proof_required');
        }

        $now = now();
        $status = $data->resultCode === 'amount_and_reference_match'
            ? WinnerPayoutReconciliationStatus::Matched
            : WinnerPayoutReconciliationStatus::Discrepancy;
        $reconciliation = WinnerPayoutReconciliation::create([
            'winner_payout_id' => $payout->id,
            'execution_attempt_id' => $attempt->id,
            'status' => $status,
            'result_code' => $data->resultCode,
            'reconciled_by_user_id' => $data->actorUserId,
            'reference_digest' => $data->reference === null ? null : hash('sha256', trim($data->reference)),
            'notes_encrypted' => $data->notes,
            'idempotency_key_hash' => $data->idempotencyKeyHash,
            'request_fingerprint' => $data->requestFingerprint,
            'reconciled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $eventType = $status === WinnerPayoutReconciliationStatus::Matched
            ? WinnerPayoutEventType::ReconciliationRecorded
            : WinnerPayoutEventType::ReconciliationDiscrepancy;
        $this->workflow->recordEvent($payout, $eventType, $payout->status->value, $payout->status->value, $data->actorUserId, 'admin', $data->resultCode, ['reconciliation_id' => (string) $reconciliation->id]);

        if ($status === WinnerPayoutReconciliationStatus::Discrepancy && $payout->status === WinnerPayoutStatus::Paid) {
            $dispute = WinnerPayoutDispute::query()->where('winner_payout_id', $payout->id)->whereIn('status', ['open', 'under_review'])->lockForUpdate()->first();
            if ($dispute === null) {
                $dispute = WinnerPayoutDispute::create([
                    'winner_payout_id' => $payout->id,
                    'winner_user_id' => $payout->user_id,
                    'status' => WinnerPayoutDisputeStatus::Open,
                    'reason_code' => 'other',
                    'opened_by_user_id' => $data->actorUserId,
                    'opened_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $payout->transitionTo(WinnerPayoutStatus::Disputed);
                $payout->updated_at = $now;
                $payout->save();
                $this->workflow->recordEvent($payout, WinnerPayoutEventType::DisputeOpened, WinnerPayoutStatus::Paid->value, WinnerPayoutStatus::Disputed->value, $data->actorUserId, 'admin', 'reconciliation_discrepancy', ['dispute_id' => (string) $dispute->id]);
                $this->outbox->execute(
                    eventType: 'winner_payout_disputed',
                    aggregateType: 'winner_payout',
                    aggregateId: (string) $payout->id,
                    deduplicationKey: 'winner_payout_disputed:'.$dispute->id,
                    payload: [
                        'schema_version' => 1,
                        'payout_id' => (string) $payout->id,
                        'dispute_id' => (string) $dispute->id,
                        'reason_code' => 'other',
                        'occurred_at' => $now->utc()->toIso8601String(),
                    ],
                );
            }
        }

        return new WinnerPayoutSettlementCommandResult((string) $payout->id, (string) $reconciliation->id, $reconciliation->status->value);
    }
}
