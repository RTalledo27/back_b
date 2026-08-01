<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Queries;

use App\Modules\Commerce\Domain\Models\WinnerPayout;

final class GetWinnerPayoutQuery
{
    public function execute(string $payoutId): WinnerPayout
    {
        return WinnerPayout::query()
            ->with([
                'game',
                'gameWinner',
                'claim',
                'currentDestination',
                'currentExecutionAttempt.destination',
                'executionAttempts.destination',
                'documents.executionAttempt',
                'events',
            ])
            ->whereKey($payoutId)
            ->firstOrFail();
    }
}
