<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Queries;

use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;

final class GetAdminWinnerClaimQuery
{
    public function find(string $claimId): ?WinnerClaim
    {
        return WinnerClaim::query()
            ->with([
                'gameWinner.game:id,slug,name,prize_cents,currency',
                'gameWinner.gameNumber:id,number',
                'gameWinner.draw:id,sequence',
                'winner:id,name,email',
                'identityProfile',
                'documents:id,winner_claim_id,document_type,mime_type,size_bytes,created_at',
            ])
            ->whereKey($claimId)
            ->first();
    }
}
