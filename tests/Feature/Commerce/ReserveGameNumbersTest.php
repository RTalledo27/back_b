<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\GameNumbersReserved;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ReserveGameNumbersTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY_A = 'idem-key-aaaaaaaaaaaaaaaa';

    private const KEY_B = 'idem-key-bbbbbbbbbbbbbbbb';

    private const KEY_C = 'idem-key-cccccccccccccccc';

    /**
     * @return array{Game, list<GameNumber>}
     */
    private function setupGame(int $numberCount = 5, GameStatus $status = GameStatus::SalesOpen, int $price = 500): array
    {
        $game = Game::create([
            'slug' => 'res-'.fake()->unique()->lexify('???????'),
            'name' => 'Rifa',
            'number_min' => 1,
            'number_max' => $numberCount,
            'hits_required' => 5,
            'ticket_price_cents' => $price,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => $status,
        ]);

        $numbers = [];
        for ($i = 1; $i <= $numberCount; $i++) {
            $numbers[] = GameNumber::create([
                'game_id' => $game->id,
                'number' => $i,
                'status' => GameNumberStatus::Available,
            ]);
        }

        return [$game, $numbers];
    }

    private function reserveHeaders(string $key = self::KEY_A): array
    {
        return ['Idempotency-Key' => $key];
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        [$game, $numbers] = $this->setupGame();

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id],
        ], $this->reserveHeaders())->assertStatus(401);
    }

    public function test_missing_idempotency_key_returns_400(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame();

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id],
        ])->assertStatus(400);
    }

    public function test_too_short_idempotency_key_returns_400(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame();

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id],
        ], ['Idempotency-Key' => 'short'])->assertStatus(400);
    }

    public function test_idempotency_key_with_invalid_chars_returns_400(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame();

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id],
        ], ['Idempotency-Key' => 'invalid key with spaces!!'])->assertStatus(400);
    }

    public function test_reserve_single_number_creates_order_items_reservation_payment_and_audit(): void
    {
        Event::fake([GameNumbersReserved::class]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        [$game, $numbers] = $this->setupGame(5, GameStatus::SalesOpen, 500);

        $response = $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[2]->id],
        ], $this->reserveHeaders());

        $response->assertCreated();
        $response->assertJsonStructure([
            'data' => [
                'order' => ['id', 'game_id', 'status', 'subtotal_cents', 'total_cents', 'currency', 'expires_at'],
                'numbers',
                'game_number_ids',
                'reservation_ids',
                'payment' => ['id', 'status', 'amount_cents', 'currency'],
            ],
        ]);
        $response->assertJsonPath('data.order.status', 'pending');
        $response->assertJsonPath('data.payment.status', 'pending');
        $response->assertJsonPath('data.order.total_cents', 500);
        $response->assertJsonPath('data.numbers', [3]);

        // DB state
        $this->assertSame(GameNumberStatus::Reserved, $numbers[2]->refresh()->status);
        $this->assertSame(GameNumberStatus::Available, $numbers[0]->refresh()->status);

        $order = Order::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(500, $order->total_cents);
        $this->assertNotNull($order->expires_at);

        $this->assertSame(1, OrderItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, NumberReservation::query()->where('order_id', $order->id)->count());

        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(500, $payment->amount_cents);

        $audit = GameEvent::query()->where('game_id', $game->id)
            ->where('type', GameEventType::NumberReserved)->firstOrFail();
        $this->assertSame($order->id, $audit->payload['order_id']);
        $this->assertSame([3], $audit->payload['numbers']);

        Event::assertDispatched(GameNumbersReserved::class, fn ($e) => $e->orderId === $order->id);
    }

    public function test_reserve_multiple_numbers_charges_count_times_unit_price(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame(5, GameStatus::SalesOpen, 500);

        $ids = [$numbers[0]->id, $numbers[2]->id, $numbers[4]->id];

        $response = $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => $ids,
        ], $this->reserveHeaders())->assertCreated();

        $response->assertJsonPath('data.order.total_cents', 1500);
        $this->assertEquals([1, 3, 5], $response->json('data.numbers'));

        foreach ([$numbers[0], $numbers[2], $numbers[4]] as $gn) {
            $this->assertSame(GameNumberStatus::Reserved, $gn->refresh()->status);
        }
        foreach ([$numbers[1], $numbers[3]] as $gn) {
            $this->assertSame(GameNumberStatus::Available, $gn->refresh()->status);
        }
    }

    public function test_client_supplied_price_currency_or_total_fields_are_ignored(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame(5, GameStatus::SalesOpen, 500);

        $response = $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id, $numbers[1]->id],
            // Attempted price-injection — must be silently discarded by validation.
            'unit_price_cents' => 1,
            'total_cents' => 1,
            'currency' => 'USD',
            'ttl_minutes' => 9999,
        ], $this->reserveHeaders())->assertCreated();

        $response->assertJsonPath('data.order.total_cents', 1000);
        $response->assertJsonPath('data.order.currency', 'PEN');
    }

    public function test_rejects_duplicate_ids_in_payload(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame();

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id, $numbers[0]->id],
        ], $this->reserveHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('game_number_ids.1');
    }

    public function test_rejects_id_belonging_to_another_game(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame();
        [$otherGame, $otherNumbers] = $this->setupGame();

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id, $otherNumbers[0]->id],
        ], $this->reserveHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_numbers_do_not_belong_to_game');

        // Atomicity: no number should have changed.
        $this->assertSame(GameNumberStatus::Available, $numbers[0]->refresh()->status);
        $this->assertSame(GameNumberStatus::Available, $otherNumbers[0]->refresh()->status);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_rejects_non_existent_id(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game] = $this->setupGame();

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [(string) Str::uuid7()],
        ], $this->reserveHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_numbers_do_not_belong_to_game');
    }

    public function test_rejects_when_game_is_not_in_sales_open(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame(5, GameStatus::Published);

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id],
        ], $this->reserveHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error', 'game_not_in_sales_open');

        $this->assertSame(GameNumberStatus::Available, $numbers[0]->refresh()->status);
    }

    public function test_if_any_number_unavailable_none_are_reserved(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame(5, GameStatus::SalesOpen, 500);

        // Pre-reserve one of the requested numbers using a separate order.
        $otherUser = User::factory()->create();
        Order::create([
            'user_id' => $otherUser->id,
            'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500,
            'total_cents' => 500,
            'currency' => 'PEN',
        ]);
        $numbers[1]->transitionTo(GameNumberStatus::Reserved);
        $numbers[1]->save();

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id, $numbers[1]->id, $numbers[2]->id],
        ], $this->reserveHeaders())
            ->assertStatus(422)
            ->assertJsonPath('error', 'number_not_available_for_reservation');

        // Other numbers must remain available — full rollback.
        $this->assertSame(GameNumberStatus::Available, $numbers[0]->refresh()->status);
        $this->assertSame(GameNumberStatus::Available, $numbers[2]->refresh()->status);
        $this->assertSame(1, Order::query()->count(), 'No new order should have been created.');
    }

    public function test_same_key_and_same_payload_returns_cached_result(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame(5, GameStatus::SalesOpen, 500);

        $body = ['game_number_ids' => [$numbers[0]->id, $numbers[1]->id]];

        $first = $this->postJson("/api/v1/games/{$game->id}/reservations", $body, $this->reserveHeaders())
            ->assertCreated();

        $second = $this->postJson("/api/v1/games/{$game->id}/reservations", $body, $this->reserveHeaders())
            ->assertCreated();

        $this->assertSame($first->json(), $second->json(), 'Replay must return the same JSON.');
        $this->assertSame(1, Order::query()->count(), 'Replay must not create a second order.');
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    public function test_same_key_with_different_payload_returns_409(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame(5, GameStatus::SalesOpen, 500);

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id],
        ], $this->reserveHeaders())->assertCreated();

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[1]->id],
        ], $this->reserveHeaders())
            ->assertStatus(409)
            ->assertJsonPath('error', 'idempotency_key_mismatch');
    }

    public function test_different_users_may_use_the_same_key_without_interference(): void
    {
        [$game, $numbers] = $this->setupGame(5, GameStatus::SalesOpen, 500);
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Sanctum::actingAs($userA);
        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id],
        ], $this->reserveHeaders())->assertCreated();

        Sanctum::actingAs($userB);
        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[1]->id],
        ], $this->reserveHeaders())->assertCreated();

        $this->assertSame(2, Order::query()->count());
        $this->assertSame(2, DB::table('idempotency_keys')->count());
    }

    public function test_id_order_does_not_affect_idempotency_result(): void
    {
        Sanctum::actingAs(User::factory()->create());
        [$game, $numbers] = $this->setupGame(5, GameStatus::SalesOpen, 500);
        $ids = [$numbers[0]->id, $numbers[1]->id, $numbers[2]->id];
        sort($ids);

        // First request with sorted ids
        $first = $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => $ids,
        ], $this->reserveHeaders())->assertCreated();

        // Replay with reversed order — must hit the cached result, not 409.
        $reversed = array_reverse($ids);
        $second = $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => $reversed,
        ], $this->reserveHeaders())->assertCreated();

        $this->assertSame($first->json(), $second->json());
    }
}
