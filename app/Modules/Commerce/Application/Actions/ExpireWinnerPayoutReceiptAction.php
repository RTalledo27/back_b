<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutDisputeStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReceiptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Domain\Models\WinnerPayoutReceipt;
use Illuminate\Support\Facades\DB;

final class ExpireWinnerPayoutReceiptAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeBatch(int $limit = 100): int
    {
        $ids = WinnerPayoutReceipt::query()
            ->where('status', WinnerPayoutReceiptStatus::Pending)
            ->where('is_legacy', false)
            ->whereNotNull('confirmation_expires_at')
            ->where('confirmation_expires_at', '<=', now())
            ->limit($limit)
            ->get(['id', 'winner_payout_id']);
        $expired = 0;

        foreach ($ids as $candidate) {
            $changed = DB::transaction(function () use ($candidate): bool {
                $payout = WinnerPayout::query()->whereKey($candidate->winner_payout_id)->lockForUpdate()->first();
                if ($payout === null || $payout->status !== WinnerPayoutStatus::Paid) {
                    return false;
                }
                $receipt = WinnerPayoutReceipt::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if ($receipt === null || $receipt->status !== WinnerPayoutReceiptStatus::Pending || $receipt->confirmation_expires_at === null || $receipt->confirmation_expires_at->isFuture()) {
                    return false;
                }
                if (WinnerPayoutDispute::query()->where('winner_payout_id', $payout->id)->whereIn('status', [WinnerPayoutDisputeStatus::Open, WinnerPayoutDisputeStatus::UnderReview])->lockForUpdate()->exists()) {
                    return false;
                }

                $now = now();
                $receipt->transitionTo(WinnerPayoutReceiptStatus::WindowExpired);
                $receipt->updated_at = $now;
                $receipt->save();
                $this->workflow->recordEvent($payout, WinnerPayoutEventType::ReceiptWindowExpired, WinnerPayoutReceiptStatus::Pending->value, WinnerPayoutReceiptStatus::WindowExpired->value, null, 'system', 'confirmation_window_expired', ['receipt_id' => (string) $receipt->id]);

                return true;
            });
            $expired += $changed ? 1 : 0;
        }

        return $expired;
    }
}
