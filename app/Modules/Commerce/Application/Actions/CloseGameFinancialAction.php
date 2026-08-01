<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\FinancialCloseGameData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutDisputeStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutExecutionAttemptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReconciliationStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReceiptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutSettlementException;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Domain\Models\WinnerPayoutExecutionAttempt;
use App\Modules\Commerce\Domain\Models\WinnerPayoutReceipt;
use App\Modules\Commerce\Domain\Models\WinnerPayoutReconciliation;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\Commerce\Domain\Models\GameFinancialClosure;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\Shared\Application\Actions\RecordOutboxEventAction;
use Illuminate\Support\Str;

final class CloseGameFinancialAction
{
    public function __construct(
        private readonly WinnerPayoutWorkflow $workflow,
        private readonly RecordOutboxEventAction $outbox,
    ) {}

    public function executeWithinTransaction(FinancialCloseGameData $data): WinnerPayoutSettlementCommandResult
    {
        $this->workflow->assertTransaction();
        $game = Game::query()->whereKey($data->gameId)->lockForUpdate()->firstOrFail();
        if ($game->status !== GameStatus::Completed) {
            throw WinnerPayoutSettlementException::closureBlocked('game_not_completed');
        }

        $funding = GamePrizeFunding::query()->where('game_id', $game->id)->lockForUpdate()->first();
        if ($funding === null || $funding->status !== GamePrizeFundingStatus::Reserved) {
            throw WinnerPayoutSettlementException::closureBlocked('funding_not_reserved');
        }
        $winner = GameWinner::query()->where('game_id', $game->id)->lockForUpdate()->first();
        if ($winner === null) {
            throw WinnerPayoutSettlementException::closureBlocked('winner_not_found');
        }
        $claim = WinnerClaim::query()->where('game_winner_id', $winner->id)->lockForUpdate()->first();
        if ($claim === null || $claim->status !== WinnerClaimStatus::Verified || $claim->is_legacy) {
            throw WinnerPayoutSettlementException::closureBlocked('claim_not_verified');
        }
        $payout = WinnerPayout::query()->where('game_winner_id', $winner->id)->lockForUpdate()->first();
        if ($payout === null) {
            throw WinnerPayoutSettlementException::closureBlocked('payout_not_paid');
        }
        if ($payout->status === WinnerPayoutStatus::LegacyRegistered) {
            throw WinnerPayoutSettlementException::closureBlocked('legacy_payout');
        }
        if ($payout->status !== WinnerPayoutStatus::Paid) {
            throw WinnerPayoutSettlementException::closureBlocked('payout_not_paid');
        }
        if ($payout->amount_cents !== $game->prize_cents || $payout->currency !== $game->currency || $funding->amount_cents !== $game->prize_cents || $funding->currency !== $game->currency) {
            throw WinnerPayoutSettlementException::closureBlocked('amount_or_currency_mismatch');
        }

        $attempt = WinnerPayoutExecutionAttempt::query()->whereKey($payout->current_execution_attempt_id)->where('winner_payout_id', $payout->id)->lockForUpdate()->first();
        if ($attempt === null || $attempt->status !== WinnerPayoutExecutionAttemptStatus::Paid) {
            throw WinnerPayoutSettlementException::closureBlocked('paid_attempt_required');
        }
        if (! $attempt->documents()->where('document_type', 'execution_proof')->exists()) {
            throw WinnerPayoutSettlementException::closureBlocked('execution_proof_required');
        }
        $receipt = WinnerPayoutReceipt::query()->where('winner_payout_id', $payout->id)->lockForUpdate()->first();
        if ($receipt === null || $receipt->is_legacy || ! in_array($receipt->status, [WinnerPayoutReceiptStatus::Confirmed, WinnerPayoutReceiptStatus::WindowExpired], true)) {
            throw WinnerPayoutSettlementException::closureBlocked('receipt_policy_not_satisfied');
        }
        if (WinnerPayoutDispute::query()->where('winner_payout_id', $payout->id)->whereIn('status', [WinnerPayoutDisputeStatus::Open, WinnerPayoutDisputeStatus::UnderReview])->lockForUpdate()->exists()) {
            throw WinnerPayoutSettlementException::closureBlocked('active_dispute');
        }
        $reconciliation = WinnerPayoutReconciliation::query()->where('winner_payout_id', $payout->id)->where('execution_attempt_id', $attempt->id)->latest('created_at')->lockForUpdate()->first();
        if ($reconciliation === null || $reconciliation->status !== WinnerPayoutReconciliationStatus::Matched) {
            throw WinnerPayoutSettlementException::closureBlocked('matched_reconciliation_required');
        }

        $now = now();
        $basis = $receipt->status === WinnerPayoutReceiptStatus::Confirmed ? 'winner_confirmed' : 'confirmation_window_expired';
        $closure = GameFinancialClosure::create([
            'game_id' => $game->id,
            'game_winner_id' => $winner->id,
            'winner_payout_id' => $payout->id,
            'winner_payout_receipt_id' => $receipt->id,
            'winner_payout_reconciliation_id' => $reconciliation->id,
            'closure_basis' => $basis,
            'closed_by_user_id' => $data->actorUserId,
            'safe_snapshot' => [
                'schema_version' => 1,
                'game_id' => (string) $game->id,
                'winner_id' => (string) $winner->id,
                'payout_id' => (string) $payout->id,
                'payout_status' => $payout->status->value,
                'receipt_status' => $receipt->status->value,
                'reconciliation_status' => $reconciliation->status->value,
                'amount_cents' => $payout->amount_cents,
                'currency' => $payout->currency,
            ],
            'correlation_id' => (string) Str::uuid7(),
            'closed_at' => $now,
            'created_at' => $now,
        ]);
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::FinanciallyClosed, WinnerPayoutStatus::Paid->value, WinnerPayoutStatus::Paid->value, $data->actorUserId, 'admin', $basis, ['closure_id' => (string) $closure->id, 'game_id' => (string) $game->id]);
        $this->outbox->execute(
            eventType: 'game_financially_closed',
            aggregateType: 'game',
            aggregateId: (string) $game->id,
            deduplicationKey: 'game_financially_closed:'.$game->id,
            payload: [
                'schema_version' => 1,
                'game_id' => (string) $game->id,
                'closure_id' => (string) $closure->id,
                'winner_payout_id' => (string) $payout->id,
                'closure_basis' => $basis,
                'occurred_at' => $now->utc()->toIso8601String(),
            ],
        );

        return new WinnerPayoutSettlementCommandResult((string) $game->id, (string) $closure->id, $basis);
    }
}
