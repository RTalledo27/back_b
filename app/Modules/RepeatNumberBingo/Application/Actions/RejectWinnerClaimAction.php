<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\DTOs\ReviewWinnerClaimData;
use App\Modules\RepeatNumberBingo\Application\DTOs\WinnerClaimReviewResult;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\WinnerClaimNotProcessable;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaimEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

final class RejectWinnerClaimAction
{
    public function executeWithinTransaction(ReviewWinnerClaimData $data): WinnerClaimReviewResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'RejectWinnerClaimAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        $winnerId = (string) WinnerClaim::query()->whereKey($data->claimId)->value('game_winner_id');
        $gameId = (string) GameWinner::query()->whereKey($winnerId)->value('game_id');
        Game::query()->whereKey($gameId)->lockForUpdate()->firstOrFail();
        $winner = GameWinner::query()->whereKey($winnerId)->lockForUpdate()->firstOrFail();
        $claim = WinnerClaim::query()->whereKey($data->claimId)->lockForUpdate()->firstOrFail();

        if ($claim->status !== WinnerClaimStatus::IdentityPending) {
            throw WinnerClaimNotProcessable::status($claim->id, $claim->status->value);
        }
        if ($data->reasonCode === null || ! in_array(
            $data->reasonCode,
            array_map('strval', (array) config('winner_claim.rejection_reason_codes', [])),
            true,
        )) {
            throw new LogicException('A valid rejection reason code is required.');
        }

        $now = now();
        $claim->transitionTo(WinnerClaimStatus::Rejected);
        $claim->rejected_at = $now;
        $claim->reviewed_by_user_id = $data->reviewerUserId;
        $claim->rejection_reason_code = $data->reasonCode;
        $claim->save();

        WinnerClaimEvent::create([
            'winner_claim_id' => $claim->id,
            'event_type' => WinnerClaimEventType::IdentityRejected,
            'from_status' => WinnerClaimStatus::IdentityPending->value,
            'to_status' => WinnerClaimStatus::Rejected->value,
            'actor_user_id' => $data->reviewerUserId,
            'actor_type' => 'admin',
            'reason_code' => $data->reasonCode,
            'safe_metadata' => [],
            'correlation_id' => null,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        return new WinnerClaimReviewResult($claim->id, $claim->status->value, $now->utc()->toIso8601String());
    }
}
