<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\WinnerPayoutSettlementCommandResult;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutDisputeStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutSettlementException;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;

final class StartWinnerPayoutDisputeReviewAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeWithinTransaction(string $disputeId, int $actorUserId): WinnerPayoutSettlementCommandResult
    {
        $this->workflow->assertTransaction();
        $payoutId = (string) WinnerPayoutDispute::query()->whereKey($disputeId)->value('winner_payout_id');
        $payout = $this->workflow->lockPayout($payoutId);
        $dispute = WinnerPayoutDispute::query()->whereKey($disputeId)->lockForUpdate()->firstOrFail();
        if ($dispute->winner_payout_id !== $payout->id || $dispute->status !== WinnerPayoutDisputeStatus::Open || $payout->status !== WinnerPayoutStatus::Disputed) {
            throw WinnerPayoutSettlementException::notEligible('review_requires_open_dispute');
        }

        $now = now();
        $dispute->transitionTo(WinnerPayoutDisputeStatus::UnderReview);
        $dispute->reviewed_by_user_id = $actorUserId;
        $dispute->review_started_at = $now;
        $dispute->updated_at = $now;
        $dispute->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::DisputeReviewStarted, WinnerPayoutStatus::Disputed->value, WinnerPayoutStatus::Disputed->value, $actorUserId, 'admin', null, ['dispute_id' => (string) $dispute->id]);

        return new WinnerPayoutSettlementCommandResult((string) $payout->id, (string) $dispute->id, $dispute->status->value);
    }
}
