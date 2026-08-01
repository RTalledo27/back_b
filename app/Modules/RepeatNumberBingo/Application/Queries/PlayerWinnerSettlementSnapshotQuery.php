<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PlayerWinnerSettlementSnapshotQuery
{
    /** @param list<string> $winnerIds @return array<string, array<string, mixed>> */
    public function forWinnerIds(array $winnerIds): array
    {
        if ($winnerIds === []) {
            return [];
        }

        $payouts = DB::table('winner_payouts as payouts')
            ->leftJoin('winner_payout_receipts as receipts', 'receipts.winner_payout_id', '=', 'payouts.id')
            ->leftJoin('game_financial_closures as closures', 'closures.winner_payout_id', '=', 'payouts.id')
            ->whereIn('payouts.game_winner_id', $winnerIds)
            ->get([
                'payouts.game_winner_id',
                'payouts.status as payout_status',
                'payouts.paid_at',
                'receipts.status as receipt_status',
                'receipts.confirmation_expires_at',
                'receipts.confirmed_at',
                'closures.id as closure_id',
                'payouts.id as payout_id',
            ])
            ->keyBy('game_winner_id');
        $payoutIds = $payouts->pluck('payout_id')->filter()->values()->all();

        $activeDisputes = DB::table('winner_payout_disputes')
            ->whereIn('winner_payout_id', $payoutIds)
            ->whereIn('status', ['open', 'under_review'])
            ->orderBy('created_at')
            ->get(['winner_payout_id', 'status'])
            ->groupBy('winner_payout_id');
        $reconciliations = DB::table('winner_payout_reconciliations')
            ->whereIn('winner_payout_id', $payoutIds)
            ->orderByDesc('created_at')
            ->get(['winner_payout_id', 'status'])
            ->groupBy('winner_payout_id');

        $snapshots = [];
        foreach ($winnerIds as $winnerId) {
            $payout = $payouts->get($winnerId);
            $dispute = $payout === null ? null : $activeDisputes->get($payout->payout_id)?->first();
            $reconciliation = $payout === null ? null : $reconciliations->get($payout->payout_id)?->first();
            $snapshots[$winnerId] = [
                'payout_status' => $payout?->payout_status,
                'paid_at' => $this->date($payout?->paid_at),
                'receipt_status' => $payout?->receipt_status,
                'confirmation_expires_at' => $this->date($payout?->confirmation_expires_at),
                'confirmed_at' => $this->date($payout?->confirmed_at),
                'has_open_dispute' => $dispute !== null,
                'dispute_status' => $dispute?->status,
                'reconciliation_public_status' => $reconciliation?->status,
                'financially_closed' => $payout?->closure_id !== null,
            ];
        }

        return $snapshots;
    }

    private function date(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }
}
