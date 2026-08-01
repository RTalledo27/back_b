<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Modules\RepeatNumberBingo\Application\Actions\ReleaseGamePrizeFundingAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ReserveGamePrizeFundingAction;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GamePrizeFundingNotProcessable;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFundingEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PrizeFundingConcurrencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_unique_game_constraint_prevents_two_funding_aggregates(): void
    {
        $game = $this->game(GamePrizeFundingStatus::Unfunded);

        $this->expectException(QueryException::class);

        GamePrizeFunding::create([
            'game_id' => $game->id,
            'status' => GamePrizeFundingStatus::Unfunded,
            'amount_cents' => 2000,
            'currency' => 'PEN',
        ]);
    }

    public function test_second_reservation_cannot_replay_after_first_commit(): void
    {
        $game = $this->game(GamePrizeFundingStatus::Funded);
        $action = $this->app->make(ReserveGamePrizeFundingAction::class);

        DB::transaction(fn () => $action->executeWithinTransaction($game, null));

        $this->expectException(GamePrizeFundingNotProcessable::class);

        DB::transaction(fn () => $action->executeWithinTransaction($game->refresh(), null));
    }

    public function test_release_is_idempotent_and_audited_once(): void
    {
        $game = $this->game(GamePrizeFundingStatus::Reserved);
        $action = $this->app->make(ReleaseGamePrizeFundingAction::class);

        DB::transaction(fn () => $action->executeWithinTransaction($game, 'game_cancelled', null));
        DB::transaction(fn () => $action->executeWithinTransaction($game->refresh(), 'game_cancelled', null));

        $funding = GamePrizeFunding::query()->where('game_id', $game->id)->firstOrFail();

        $this->assertSame(GamePrizeFundingStatus::Released, $funding->status);
        $this->assertSame(1, GamePrizeFundingEvent::query()
            ->where('game_prize_funding_id', $funding->id)
            ->where('event_type', GamePrizeFundingEventType::FundingReleased)
            ->count());
    }

    public function test_game_is_locked_before_funding_for_lifecycle_operations(): void
    {
        $game = $this->game(GamePrizeFundingStatus::Funded);
        $tables = [];

        DB::listen(function ($query) use (&$tables): void {
            if (! str_contains(mb_strtolower((string) $query->sql), 'for update')) {
                return;
            }

            foreach (['games', 'game_prize_fundings'] as $table) {
                if (preg_match('/\bfrom\s+"?'.preg_quote($table, '/').'"?/i', (string) $query->sql) === 1) {
                    $tables[] = $table;

                    return;
                }
            }
        });

        DB::transaction(fn () => $this->app->make(ReserveGamePrizeFundingAction::class)
            ->executeWithinTransaction($game, null));

        $this->assertSame(['game_prize_fundings'], $tables);
    }

    private function game(GamePrizeFundingStatus $status): Game
    {
        $game = Game::create([
            'slug' => 'concurrency-'.fake()->unique()->lexify('??????'),
            'name' => 'Concurrency',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 2,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::SalesClosed,
        ]);

        GamePrizeFunding::create([
            'game_id' => $game->id,
            'status' => $status,
            'amount_cents' => 2000,
            'currency' => 'PEN',
        ]);

        return $game;
    }
}
