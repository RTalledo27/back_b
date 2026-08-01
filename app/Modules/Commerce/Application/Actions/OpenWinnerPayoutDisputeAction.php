<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\OpenWinnerPayoutDisputeData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutDisputeStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReceiptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutSettlementException;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Domain\Models\WinnerPayoutReceipt;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\Shared\Application\Actions\RecordOutboxEventAction;

final class OpenWinnerPayoutDisputeAction
{
    public function __construct(
        private readonly WinnerPayoutWorkflow $workflow,
        private readonly RecordOutboxEventAction $outbox,
    ) {}

    public function executeWithinTransaction(OpenWinnerPayoutDisputeData $data): WinnerPayoutSettlementCommandResult
    {
        $this->workflow->assertTransaction();
        $winner = GameWinner::query()->whereKey($data->winnerId)->lockForUpdate()->firstOrFail();
        $payout = WinnerPayout::query()->where('game_winner_id', $winner->id)->lockForUpdate()->first();

        if ($payout === null || (int) $payout->user_id !== $data->actorUserId) {
            throw WinnerPayoutSettlementException::ownership();
        }
        if ($payout->status !== WinnerPayoutStatus::Paid) {
            throw WinnerPayoutSettlementException::notEligible('dispute_requires_paid_payout');
        }

        $receipt = WinnerPayoutReceipt::query()->where('winner_payout_id', $payout->id)->lockForUpdate()->first();
        if ($receipt === null || $receipt->is_legacy || $receipt->status === WinnerPayoutReceiptStatus::Confirmed) {
            throw WinnerPayoutSettlementException::notEligible('dispute_requires_unconfirmed_receipt');
        }
        if (WinnerPayoutDispute::query()->where('winner_payout_id', $payout->id)->whereIn('status', ['open', 'under_review'])->lockForUpdate()->exists()) {
            throw WinnerPayoutSettlementException::duplicateDispute();
        }
        if ($data->description !== null && preg_match('/\b(?:cci|account|cuenta|password|secret|token|pin|cvv)\b|\b\d{7,}\b/i', $data->description) === 1) {
            throw WinnerPayoutSettlementException::unsafeDescription();
        }

        $now = now();
        $dispute = WinnerPayoutDispute::create([
            'winner_payout_id' => $payout->id,
            'winner_user_id' => $data->actorUserId,
            'status' => WinnerPayoutDisputeStatus::Open,
            'reason_code' => $data->reasonCode,
            'description_encrypted' => $data->description,
            'opened_by_user_id' => $data->actorUserId,
            'opened_at' => $now,
            'idempotency_key_hash' => $data->idempotencyKeyHash,
            'request_fingerprint' => $data->requestFingerprint,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payout->transitionTo(WinnerPayoutStatus::Disputed);
        $payout->updated_at = $now;
        $payout->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::DisputeOpened, WinnerPayoutStatus::Paid->value, WinnerPayoutStatus::Disputed->value, $data->actorUserId, 'winner', $data->reasonCode, ['dispute_id' => (string) $dispute->id]);
        $this->outbox->execute(
            eventType: 'winner_payout_disputed',
            aggregateType: 'winner_payout',
            aggregateId: (string) $payout->id,
            deduplicationKey: 'winner_payout_disputed:'.$dispute->id,
            payload: [
                'schema_version' => 1,
                'payout_id' => (string) $payout->id,
                'dispute_id' => (string) $dispute->id,
                'winner_user_id' => $data->actorUserId,
                'reason_code' => $data->reasonCode,
                'occurred_at' => $now->utc()->toIso8601String(),
            ],
        );

        return new WinnerPayoutSettlementCommandResult((string) $payout->id, (string) $dispute->id, $dispute->status->value);
    }
}
