<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameScheduledStartSet;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameConfiguration;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Configures (or reconfigures) the scheduled_start_at attribute of a game.
 * Does NOT transition the game's status. Allowed only while the game is in
 * a pre-running state where the schedule is still mutable.
 */
final class SetScheduledStartAtAction
{
    public function execute(
        string $gameId,
        DateTimeImmutable $scheduledStartAt,
        ?int $actorUserId = null,
    ): Game {
        $game = DB::transaction(function () use ($gameId, $scheduledStartAt, $actorUserId): Game {
            /** @var Game $game */
            $game = Game::query()
                ->whereKey($gameId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($game->status, GameStatus::statesWhereScheduledStartIsConfigurable(), true)) {
                throw new InvalidGameConfiguration(
                    "Scheduled start cannot be modified while game is {$game->status->value}."
                );
            }

            if ($scheduledStartAt <= new DateTimeImmutable) {
                throw new InvalidGameConfiguration('Scheduled start must be in the future.');
            }

            if ($game->sales_closes_at !== null
                && $scheduledStartAt <= $game->sales_closes_at->toDateTimeImmutable()) {
                throw new InvalidGameConfiguration(
                    'Scheduled start must be after sales_closes_at.'
                );
            }

            $game->scheduled_start_at = $scheduledStartAt;
            $game->save();

            GameEvent::create([
                'game_id' => $game->id,
                'type' => GameEventType::ScheduledStartSet,
                'payload' => ['scheduled_start_at' => $scheduledStartAt->format(DATE_ATOM)],
                'actor_user_id' => $actorUserId,
                'occurred_at' => now(),
            ]);

            return $game;
        });

        GameScheduledStartSet::dispatch($game->id, $scheduledStartAt);

        return $game;
    }
}
