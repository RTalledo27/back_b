<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaimEvent;
use Illuminate\Support\Facades\DB;

final class ExpireWinnerClaimAction
{
    public function execute(string $claimId): bool
    {
        return DB::transaction(function () use ($claimId): bool {
            $winnerId = (string) WinnerClaim::query()->whereKey($claimId)->value('game_winner_id');
            if ($winnerId === '') {
                return false;
            }

            $gameId = (string) GameWinner::query()->whereKey($winnerId)->value('game_id');
            Game::query()->whereKey($gameId)->lockForUpdate()->firstOrFail();
            GameWinner::query()->whereKey($winnerId)->lockForUpdate()->firstOrFail();
            $claim = WinnerClaim::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();

            if ($claim->status !== WinnerClaimStatus::PendingClaim
                || $claim->expires_at === null
                || $claim->expires_at->isFuture()) {
                return false;
            }

            $now = now();
            $claim->transitionTo(WinnerClaimStatus::Expired);
            $claim->expired_at = $now;
            $claim->save();

            WinnerClaimEvent::create([
                'winner_claim_id' => $claim->id,
                'event_type' => WinnerClaimEventType::ClaimExpired,
                'from_status' => WinnerClaimStatus::PendingClaim->value,
                'to_status' => WinnerClaimStatus::Expired->value,
                'actor_user_id' => null,
                'actor_type' => 'system',
                'reason_code' => 'claim_window_expired',
                'safe_metadata' => [],
                'correlation_id' => null,
                'occurred_at' => $now,
                'created_at' => $now,
            ]);

            return true;
        });
    }
}
