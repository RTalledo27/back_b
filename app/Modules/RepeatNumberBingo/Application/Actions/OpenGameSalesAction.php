<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameSalesOpened;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use Illuminate\Support\Facades\DB;

final class OpenGameSalesAction
{
    public function execute(string $gameId, ?int $actorUserId = null): Game
    {
        $game = DB::transaction(function () use ($gameId, $actorUserId): Game {
            /** @var Game $game */
            $game = Game::query()
                ->whereKey($gameId)
                ->lockForUpdate()
                ->firstOrFail();

            $game->transitionTo(GameStatus::SalesOpen);

            if ($game->sales_opens_at === null) {
                $game->sales_opens_at = now();
            }

            $game->save();

            GameEvent::create([
                'game_id' => $game->id,
                'type' => GameEventType::SalesOpened,
                'payload' => null,
                'actor_user_id' => $actorUserId,
                'occurred_at' => now(),
            ]);

            return $game;
        });

        GameSalesOpened::dispatch($game->id);

        return $game;
    }
}
