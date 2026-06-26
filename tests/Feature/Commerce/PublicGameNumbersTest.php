<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class PublicGameNumbersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_endpoint_returns_only_public_reservation_contract_fields(): void
    {
        $owner = User::factory()->create();
        $game = Game::create([
            'slug' => 'public-numbers',
            'name' => 'P', 'number_min' => 1, 'number_max' => 3, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesOpen,
        ]);
        $reserved = GameNumber::create(['game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Reserved]);
        GameNumber::create(['game_id' => $game->id, 'number' => 2, 'status' => GameNumberStatus::Available]);
        GameNumber::create(['game_id' => $game->id, 'number' => 3, 'status' => GameNumberStatus::Sold]);
        $order = Order::create([
            'user_id' => $owner->id, 'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500, 'total_cents' => 500,
            'currency' => 'PEN', 'expires_at' => now()->addHour(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'game_number_id' => $reserved->id, 'unit_price_cents' => 500]);
        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $reserved->id]);

        $response = $this->getJson('/api/v1/public/games/public-numbers/numbers')->assertOk();

        $data = $response->json('data');
        $this->assertCount(3, $data);

        $expectedIds = [
            $reserved->id,
            GameNumber::query()->where('game_id', $game->id)->where('number', 2)->value('id'),
            GameNumber::query()->where('game_id', $game->id)->where('number', 3)->value('id'),
        ];

        foreach ($data as $row) {
            $this->assertSame(['id', 'number', 'status'], array_keys($row));
            $this->assertContains($row['id'], $expectedIds);
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $row['id'],
            );
            $this->assertContains($row['status'], ['available', 'reserved', 'sold']);
            $this->assertIsInt($row['number']);
        }

        // Explicitly assert NO leaking fields.
        $json = json_encode($data);
        $this->assertStringNotContainsString('game_id', $json);
        $this->assertStringNotContainsString('user_id', $json);
        $this->assertStringNotContainsString('order_id', $json);
        $this->assertStringNotContainsString('payment_id', $json);
        $this->assertStringNotContainsString('reservation_id', $json);
        $this->assertStringNotContainsString('created_at', $json);
        $this->assertStringNotContainsString('updated_at', $json);
        $this->assertStringNotContainsString((string) $owner->email, $json);
    }

    public function test_public_endpoint_404_for_draft_game(): void
    {
        Game::create([
            'slug' => 'hidden-game', 'name' => 'H',
            'number_min' => 1, 'number_max' => 3, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::Draft,
        ]);

        $this->getJson('/api/v1/public/games/hidden-game/numbers')->assertNotFound();
    }
}
