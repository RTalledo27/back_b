<?php

declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\InvalidGameNumberTransition;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class GameNumberTransitionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createGameNumber(GameNumberStatus $status = GameNumberStatus::Available): GameNumber
    {
        $game = Game::create([
            'slug' => 'gn-'.fake()->unique()->lexify('?????'),
            'name' => 'GN',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ]);

        return GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => $status,
        ]);
    }

    public function test_available_to_reserved_is_allowed(): void
    {
        $gn = $this->createGameNumber();

        $gn->transitionTo(GameNumberStatus::Reserved);
        $gn->save();

        $this->assertSame(GameNumberStatus::Reserved, $gn->refresh()->status);
    }

    public function test_reserved_to_sold_is_allowed(): void
    {
        $gn = $this->createGameNumber(GameNumberStatus::Reserved);

        $gn->transitionTo(GameNumberStatus::Sold);
        $gn->save();

        $this->assertSame(GameNumberStatus::Sold, $gn->refresh()->status);
    }

    public function test_reserved_back_to_available_is_allowed(): void
    {
        $gn = $this->createGameNumber(GameNumberStatus::Reserved);

        $gn->transitionTo(GameNumberStatus::Available);
        $gn->save();

        $this->assertSame(GameNumberStatus::Available, $gn->refresh()->status);
    }

    public function test_available_to_sold_throws(): void
    {
        $gn = $this->createGameNumber();

        $this->expectException(InvalidGameNumberTransition::class);

        $gn->transitionTo(GameNumberStatus::Sold);
    }

    public function test_sold_to_available_is_allowed_for_admin_refunds(): void
    {
        $gn = $this->createGameNumber(GameNumberStatus::Sold);

        $gn->transitionTo(GameNumberStatus::Available);
        $gn->save();

        $this->assertSame(GameNumberStatus::Available, $gn->refresh()->status);
    }

    public function test_sold_to_reserved_is_forbidden(): void
    {
        $gn = $this->createGameNumber(GameNumberStatus::Sold);

        $this->expectException(InvalidGameNumberTransition::class);

        $gn->transitionTo(GameNumberStatus::Reserved);
    }
}
