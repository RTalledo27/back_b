<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameCancelled;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use Illuminate\Support\Facades\DB;

final class CancelGameAction
{
    public function execute(string $gameId, ?string $reason = null, ?int $actorUserId = null): Game
    {
        $game = DB::transaction(function () use ($gameId, $reason, $actorUserId): Game {
            /** @var Game $game */
            $game = Game::query()
                ->whereKey($gameId)
                ->lockForUpdate()
                ->firstOrFail();

            $game->transitionTo(GameStatus::Cancelled);
            $game->save();

            GameEvent::create([
                'game_id' => $game->id,
                'type' => GameEventType::GameCancelled,
                'payload' => $reason !== null ? ['reason' => $reason] : null,
                'actor_user_id' => $actorUserId,
                'occurred_at' => now(),
            ]);

            return $game;
        });

        GameCancelled::dispatch($game->id, $reason);

        return $game;
    }
}
