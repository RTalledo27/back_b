<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFundingEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ReleaseGamePrizeFundingAction
{
    public function executeWithinTransaction(
        Game $game,
        string $reasonCode,
        ?int $actorUserId = null,
    ): ?GamePrizeFunding {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'ReleaseGamePrizeFundingAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        /** @var ?GamePrizeFunding $funding */
        $funding = GamePrizeFunding::query()
            ->where('game_id', $game->id)
            ->lockForUpdate()
            ->first();

        if ($funding === null || ! in_array($funding->status, [
            GamePrizeFundingStatus::Funded,
            GamePrizeFundingStatus::Reserved,
        ], true)) {
            return $funding;
        }

        $fromStatus = $funding->status->value;
        $releasedAt = now();
        $funding->transitionTo(GamePrizeFundingStatus::Released);
        $funding->released_at = $releasedAt;
        $funding->release_reason_code = $reasonCode;
        $funding->save();

        GamePrizeFundingEvent::forceCreate([
            'game_prize_funding_id' => $funding->id,
            'event_type' => GamePrizeFundingEventType::FundingReleased,
            'from_status' => $fromStatus,
            'to_status' => GamePrizeFundingStatus::Released->value,
            'actor_user_id' => $actorUserId,
            'reason_code' => $reasonCode,
            'safe_metadata' => [],
            'correlation_id' => null,
            'occurred_at' => $releasedAt,
            'created_at' => $releasedAt,
        ]);

        return $funding;
    }
}
