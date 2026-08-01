<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\MarkWinnerPayoutPaidData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutExecutionAttemptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutWorkflowException;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDocument;
use App\Modules\Commerce\Domain\Models\WinnerPayoutExecutionAttempt;

final class MarkWinnerPayoutPaidAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeWithinTransaction(MarkWinnerPayoutPaidData $data): WinnerPayoutCommandResult
    {
        $this->workflow->assertTransaction();
        $payout = $this->workflow->lockPayout($data->payoutId);
        if ($payout->status !== WinnerPayoutStatus::Processing) {
            throw WinnerPayoutWorkflowException::notEligible('paid_requires_processing');
        }
        if ((int) $payout->created_by_user_id === $data->actorUserId) {
            throw WinnerPayoutWorkflowException::actorSeparation();
        }
        if ((int) $payout->user_id === $data->actorUserId) {
            throw WinnerPayoutWorkflowException::winnerActorSeparation();
        }

        $attempt = WinnerPayoutExecutionAttempt::query()
            ->whereKey($payout->current_execution_attempt_id)
            ->where('winner_payout_id', $payout->id)
            ->lockForUpdate()
            ->first();
        if ($attempt === null || $attempt->status !== WinnerPayoutExecutionAttemptStatus::Processing) {
            throw WinnerPayoutWorkflowException::processingAttemptRequired();
        }
        if (trim($data->externalReference) === '') {
            throw WinnerPayoutWorkflowException::executionEvidenceRequired();
        }

        $now = now();
        $document = WinnerPayoutDocument::create([
            'payout_id' => $payout->id,
            'execution_attempt_id' => $attempt->id,
            'document_type' => 'execution_proof',
            'disk' => $data->documentDisk,
            'path' => $data->documentPath,
            'original_filename' => $data->documentOriginalFilename,
            'mime_type' => $data->documentMimeType,
            'size_bytes' => $data->documentSizeBytes,
            'sha256' => $data->documentSha256,
            'uploaded_by' => $data->actorUserId,
            'created_at' => $now,
        ]);

        $attempt->transitionTo(WinnerPayoutExecutionAttemptStatus::Paid);
        $attempt->completed_by_user_id = $data->actorUserId;
        $attempt->external_reference_encrypted = $data->externalReference;
        $attempt->external_reference_masked = $this->mask($data->externalReference);
        $attempt->paid_at = $now;
        $attempt->save();

        $payout->transitionTo(WinnerPayoutStatus::Paid);
        $payout->executed_by_user_id = $data->actorUserId;
        $payout->external_reference = $data->externalReference;
        $payout->paid_at = $now;
        $payout->updated_at = $now;
        $payout->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::ExecutionRecorded, WinnerPayoutStatus::Processing->value, WinnerPayoutStatus::Paid->value, $data->actorUserId, 'admin', null, ['attempt_number' => $attempt->attempt_number]);

        return $this->workflow->result($payout, true, (string) $attempt->id, (string) $document->id);
    }

    private function mask(string $value): string
    {
        $value = trim($value);

        return strlen($value) <= 4 ? '****' : '****'.substr($value, -4);
    }
}
