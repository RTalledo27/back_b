<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GamePrizeFundingNotProcessable;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFundingEvent;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ReserveGamePrizeFundingAction
{
    public function executeWithinTransaction(Game $game, ?int $actorUserId = null): ?GamePrizeFunding
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'ReserveGamePrizeFundingAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        /** @var ?GamePrizeFunding $funding */
        $funding = GamePrizeFunding::query()
            ->where('game_id', $game->id)
            ->lockForUpdate()
            ->first();

        if ($funding === null) {
            return null;
        }

        if ($funding->status !== GamePrizeFundingStatus::Funded) {
            throw GamePrizeFundingNotProcessable::notReady($game->id, $funding->status->value);
        }

        $reservedAt = now();
        $funding->transitionTo(GamePrizeFundingStatus::Reserved);
        $funding->reserved_at = $reservedAt;
        $funding->save();

        GamePrizeFundingEvent::forceCreate([
            'game_prize_funding_id' => $funding->id,
            'event_type' => GamePrizeFundingEventType::FundingReserved,
            'from_status' => GamePrizeFundingStatus::Funded->value,
            'to_status' => GamePrizeFundingStatus::Reserved->value,
            'actor_user_id' => $actorUserId,
            'reason_code' => 'game_started',
            'safe_metadata' => [],
            'correlation_id' => null,
            'occurred_at' => $reservedAt,
            'created_at' => $reservedAt,
        ]);

        return $funding;
    }
}
