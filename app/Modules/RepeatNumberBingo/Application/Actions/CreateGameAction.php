<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Application\DTOs\CreateGameData;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameCreated;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Services\GameNumberGenerator;
use Illuminate\Support\Facades\DB;

final class CreateGameAction
{
    public function __construct(
        private readonly GameNumberGenerator $numberGenerator,
    ) {}

    public function execute(CreateGameData $data): Game
    {
        $game = DB::transaction(function () use ($data): Game {
            $game = new Game([
                'slug' => $data->slug,
                'name' => $data->name,
                'description' => $data->description,
                'number_min' => $data->range->min,
                'number_max' => $data->range->max,
                'hits_required' => $data->range->hitsRequired,
                'ticket_price_cents' => $data->ticketPrice->amountInCents,
                'prize_cents' => $data->prize->amountInCents,
                'currency' => $data->ticketPrice->currency,
                'sales_opens_at' => $data->salesOpensAt,
                'sales_closes_at' => $data->salesClosesAt,
                'scheduled_start_at' => $data->scheduledStartAt,
                'draw_interval_seconds' => $data->drawIntervalSeconds,
                'auto_draw_enabled' => $data->autoDrawEnabled,
                'status' => GameStatus::Draft,
                'settings' => $data->settings,
                'created_by' => $data->createdBy,
            ]);

            $game->save();

            $this->numberGenerator->generateFor($game, $data->range);

            GameEvent::create([
                'game_id' => $game->id,
                'type' => GameEventType::GameCreated,
                'payload' => [
                    'slug' => $game->slug,
                    'number_count' => $data->range->count(),
                ],
                'actor_user_id' => $data->createdBy,
                'occurred_at' => now(),
            ]);

            return $game;
        });

        GameCreated::dispatch($game->id);

        return $game;
    }
}
