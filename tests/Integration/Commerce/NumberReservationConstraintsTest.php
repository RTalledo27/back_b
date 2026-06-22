<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class NumberReservationConstraintsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setupGameNumber(): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'res-test-'.fake()->unique()->lexify('?????'),
            'name' => 'X',
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
        $gameNumber = GameNumber::create([
            'game_id' => $game->id,
            'number' => 7,
            'status' => GameNumberStatus::Available,
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'subtotal_cents' => 100,
            'total_cents' => 100,
            'currency' => 'PEN',
            'status' => OrderStatus::Pending,
        ]);

        return [$order, $gameNumber];
    }

    public function test_unique_per_game_number_blocks_double_hold(): void
    {
        [$order, $gameNumber] = $this->setupGameNumber();

        NumberReservation::create([
            'order_id' => $order->id,
            'game_number_id' => $gameNumber->id,
        ]);

        // A different order trying to reserve the same number must fail.
        $otherUser = User::factory()->create();
        $otherOrder = Order::create([
            'user_id' => $otherUser->id,
            'game_id' => $order->game_id,
            'subtotal_cents' => 100,
            'total_cents' => 100,
            'currency' => 'PEN',
            'status' => OrderStatus::Pending,
        ]);

        $this->expectException(QueryException::class);

        NumberReservation::create([
            'order_id' => $otherOrder->id,
            'game_number_id' => $gameNumber->id,
        ]);
    }

    public function test_reservation_can_be_recreated_after_hard_delete(): void
    {
        [$order, $gameNumber] = $this->setupGameNumber();

        $first = NumberReservation::create([
            'order_id' => $order->id,
            'game_number_id' => $gameNumber->id,
        ]);

        $first->delete();

        $second = NumberReservation::create([
            'order_id' => $order->id,
            'game_number_id' => $gameNumber->id,
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, NumberReservation::query()->count());
    }
}
