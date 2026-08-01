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

final class VerifyWinnerClaimAction
{
    public function executeWithinTransaction(ReviewWinnerClaimData $data): WinnerClaimReviewResult
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'VerifyWinnerClaimAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        $winnerId = (string) WinnerClaim::query()->whereKey($data->claimId)->value('game_winner_id');
        $gameId = (string) GameWinner::query()->whereKey($winnerId)->value('game_id');
        Game::query()->whereKey($gameId)->lockForUpdate()->firstOrFail();
        $winner = GameWinner::query()->whereKey($winnerId)->lockForUpdate()->firstOrFail();
        $claim = WinnerClaim::query()->whereKey($data->claimId)->lockForUpdate()->firstOrFail();

        if ((int) $winner->user_id === $data->reviewerUserId) {
            throw WinnerClaimNotProcessable::selfReview();
        }
        if ($claim->status !== WinnerClaimStatus::IdentityPending) {
            throw WinnerClaimNotProcessable::status($claim->id, $claim->status->value);
        }
        if ($claim->identityProfile === null || $claim->documents()->count() === 0) {
            throw WinnerClaimNotProcessable::missingDocuments($claim->id);
        }

        $now = now();
        $claim->transitionTo(WinnerClaimStatus::Verified);
        $claim->verified_at = $now;
        $claim->reviewed_by_user_id = $data->reviewerUserId;
        $claim->save();

        WinnerClaimEvent::create([
            'winner_claim_id' => $claim->id,
            'event_type' => WinnerClaimEventType::IdentityVerified,
            'from_status' => WinnerClaimStatus::IdentityPending->value,
            'to_status' => WinnerClaimStatus::Verified->value,
            'actor_user_id' => $data->reviewerUserId,
            'actor_type' => 'admin',
            'reason_code' => 'identity_verified',
            'safe_metadata' => ['document_count' => $claim->documents()->count()],
            'correlation_id' => null,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        return new WinnerClaimReviewResult($claim->id, $claim->status->value, $now->utc()->toIso8601String());
    }
}
