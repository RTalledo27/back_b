<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\ConfirmWinnerPayoutReceiptData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReceiptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutSettlementException;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Domain\Models\WinnerPayoutReceipt;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\Shared\Application\Actions\RecordOutboxEventAction;

final class ConfirmWinnerPayoutReceiptAction
{
    public function __construct(
        private readonly WinnerPayoutWorkflow $workflow,
        private readonly RecordOutboxEventAction $outbox,
    ) {}

    public function executeWithinTransaction(ConfirmWinnerPayoutReceiptData $data): WinnerPayoutSettlementCommandResult
    {
        $this->workflow->assertTransaction();
        $winner = GameWinner::query()->whereKey($data->winnerId)->lockForUpdate()->firstOrFail();
        $payout = WinnerPayout::query()->where('game_winner_id', $winner->id)->lockForUpdate()->first();

        if ($payout === null || (int) $payout->user_id !== $data->actorUserId) {
            throw WinnerPayoutSettlementException::ownership();
        }
        if ($payout->status !== WinnerPayoutStatus::Paid) {
            throw WinnerPayoutSettlementException::notEligible('receipt_requires_paid_payout');
        }

        $receipt = WinnerPayoutReceipt::query()->where('winner_payout_id', $payout->id)->lockForUpdate()->first();
        if ($receipt === null || $receipt->is_legacy) {
            throw WinnerPayoutSettlementException::notEligible('receipt_not_found');
        }
        if ($receipt->status !== WinnerPayoutReceiptStatus::Pending) {
            throw WinnerPayoutSettlementException::notEligible('receipt_not_pending');
        }
        if (WinnerPayoutDispute::query()->where('winner_payout_id', $payout->id)->whereIn('status', ['open', 'under_review'])->exists()) {
            throw WinnerPayoutSettlementException::activeDispute();
        }

        $now = now();
        $receipt->transitionTo(WinnerPayoutReceiptStatus::Confirmed);
        $receipt->confirmed_at = $now;
        $receipt->confirmation_method = 'explicit_player_confirmation';
        $receipt->updated_at = $now;
        $receipt->save();

        $this->workflow->recordEvent($payout, WinnerPayoutEventType::ReceiptConfirmed, WinnerPayoutReceiptStatus::Pending->value, WinnerPayoutReceiptStatus::Confirmed->value, $data->actorUserId, 'winner', null, ['receipt_id' => (string) $receipt->id]);
        $this->outbox->execute(
            eventType: 'winner_payout_receipt_confirmed',
            aggregateType: 'winner_payout',
            aggregateId: (string) $payout->id,
            deduplicationKey: 'winner_payout_receipt_confirmed:'.$receipt->id,
            payload: [
                'schema_version' => 1,
                'payout_id' => (string) $payout->id,
                'receipt_id' => (string) $receipt->id,
                'winner_user_id' => $data->actorUserId,
                'occurred_at' => $now->utc()->toIso8601String(),
            ],
        );

        return new WinnerPayoutSettlementCommandResult((string) $payout->id, (string) $receipt->id, $receipt->status->value);
    }
}
