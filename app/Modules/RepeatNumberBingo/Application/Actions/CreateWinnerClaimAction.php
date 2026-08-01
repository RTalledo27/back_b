<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaimEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class CreateWinnerClaimAction
{
    public function executeWithinTransaction(string $winnerId, ?int $actorUserId = null): WinnerClaim
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'CreateWinnerClaimAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        /** @var GameWinner $winner */
        $winner = GameWinner::query()->whereKey($winnerId)->lockForUpdate()->firstOrFail();
        $existing = WinnerClaim::query()
            ->where('game_winner_id', $winner->id)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $ttlDays = (int) config('winner_claim.ttl_days', 30);
        if ($ttlDays < 1 || $ttlDays > 3650) {
            throw new LogicException('WINNER_CLAIM_TTL_DAYS must be between 1 and 3650.');
        }

        $startedAt = CarbonImmutable::instance($winner->won_at);
        $claim = WinnerClaim::create([
            'game_winner_id' => $winner->id,
            'winner_user_id' => $winner->user_id,
            'claim_reference' => $this->newClaimReference(),
            'status' => WinnerClaimStatus::PendingClaim,
            'claim_window_started_at' => $startedAt,
            'expires_at' => $startedAt->addDays($ttlDays),
            'is_legacy' => false,
        ]);

        WinnerClaimEvent::create([
            'winner_claim_id' => $claim->id,
            'event_type' => WinnerClaimEventType::ClaimCreated,
            'from_status' => null,
            'to_status' => WinnerClaimStatus::PendingClaim->value,
            'actor_user_id' => $actorUserId,
            'actor_type' => 'game_engine',
            'reason_code' => 'winner_declared',
            'safe_metadata' => ['is_legacy' => false],
            'correlation_id' => null,
            'occurred_at' => $startedAt,
            'created_at' => $startedAt,
        ]);

        return $claim;
    }

    private function newClaimReference(): string
    {
        do {
            $reference = 'CLAIM-'.strtoupper(Str::random(32));
        } while (WinnerClaim::query()->where('claim_reference', $reference)->exists());

        return $reference;
    }
}
