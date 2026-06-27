<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderRefunded;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\Commerce\Domain\Models\Refund;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RefundOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const REASON = 'El jugador solicitó reembolso por error en la compra.';

    private const KEY_A = 'refund-key-aaaaaaaaaaaaaaaa';

    private const KEY_B = 'refund-key-bbbbbbbbbbbbbbbb';

    /**
     * Creates a fully paid order: Order=Paid, Payment=Approved,
     * GameNumbers=Sold, GameEntries=Confirmed, PurchaseAllocations set.
     *
     * @return array{User, User, Game, Order, Payment, list<GameNumber>, list<GameEntry>}
     */
    private function setupPaidOrder(int $numberCount = 2, GameStatus $gameStatus = GameStatus::SalesOpen): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $game = Game::create([
            'slug' => 'rf-'.fake()->unique()->lexify('?????'),
            'name' => 'Rifa Test',
            'number_min' => 1, 'number_max' => 20, 'hits_required' => 10,
            'ticket_price_cents' => 1000, 'prize_cents' => 5000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => $gameStatus,
        ]);

        $gameNumbers = [];
        for ($i = 1; $i <= $numberCount; $i++) {
            $gameNumbers[] = GameNumber::create([
                'game_id' => $game->id,
                'number' => $i,
                'status' => GameNumberStatus::Sold,
            ]);
        }

        $totalCents = 1000 * $numberCount;

        $order = Order::create([
            'user_id' => $buyer->id,
            'game_id' => $game->id,
            'status' => OrderStatus::Paid,
            'subtotal_cents' => $totalCents,
            'total_cents' => $totalCents,
            'currency' => 'PEN',
            'expires_at' => null,
            'paid_at' => now()->subMinutes(5),
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => $totalCents,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Approved,
            'submitted_at' => now()->subMinutes(10),
            'reviewed_at' => now()->subMinutes(5),
            'reviewed_by' => $admin->id,
        ]);

        $entries = [];
        foreach ($gameNumbers as $gn) {
            $orderItem = OrderItem::create([
                'order_id' => $order->id,
                'game_number_id' => $gn->id,
                'unit_price_cents' => 1000,
            ]);

            $entry = GameEntry::create([
                'game_id' => $game->id,
                'game_number_id' => $gn->id,
                'user_id' => $buyer->id,
                'status' => EntryStatus::Confirmed,
                'confirmed_at' => now()->subMinutes(5),
            ]);

            PurchaseAllocation::create([
                'order_item_id' => $orderItem->id,
                'game_entry_id' => $entry->id,
                'payment_id' => $payment->id,
            ]);

            $entries[] = $entry;
        }

        return [$buyer, $admin, $game, $order, $payment, $gameNumbers, $entries];
    }

    // ── POST /api/v1/admin/orders/{order}/refund ──────────────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        [, , , $order] = $this->setupPaidOrder();

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])->assertStatus(401);
    }

    public function test_player_cannot_refund_order_returns_403(): void
    {
        [$buyer, , , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])->assertStatus(403);
    }

    public function test_missing_idempotency_key_returns_400(): void
    {
        [, $admin, , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ])->assertStatus(400);
    }

    public function test_missing_reason_returns_422(): void
    {
        [, $admin, , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [], [
            'Idempotency-Key' => self::KEY_A,
        ])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_reason_too_short_returns_422(): void
    {
        [, $admin, , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => 'short',
        ], ['Idempotency-Key' => self::KEY_A])->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_order_not_found_returns_404(): void
    {
        [, $admin] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);
        $fakeId = '00000000-0000-7000-8000-000000000001';

        $this->postJson("/api/v1/admin/orders/{$fakeId}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])->assertStatus(404);
    }

    public function test_happy_path_refunds_order_and_returns_correct_resource(): void
    {
        Event::fake([OrderRefunded::class]);
        [, $admin, $game, $order, $payment, $gameNumbers, $entries] = $this->setupPaidOrder(numberCount: 2);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A]);

        $response->assertOk();
        $response->assertJsonPath('data.order_id', $order->id);
        $response->assertJsonPath('data.payment_id', $payment->id);
        $response->assertJsonPath('data.game_id', $game->id);
        $response->assertJsonPath('data.amount_cents', $order->total_cents);
        $response->assertJsonPath('data.currency', 'PEN');
        $response->assertJsonPath('data.reason', self::REASON);
        $response->assertJsonPath('data.processed_by_user_id', $admin->id);
        $response->assertJsonPath('data.entries.count', 2);
        $this->assertSame([1, 2], $response->json('data.numbers'));

        // No sensitive fields in response
        $this->assertArrayNotHasKey('idempotency_key_hash', $response->json('data'));
        $this->assertArrayNotHasKey('request_fingerprint', $response->json('data'));

        // Order and Payment transitioned
        $this->assertSame(OrderStatus::Refunded, $order->refresh()->status);
        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);

        // GameNumbers returned to available
        foreach ($gameNumbers as $gn) {
            $this->assertSame(GameNumberStatus::Available, $gn->refresh()->status);
        }

        // GameEntries marked refunded
        foreach ($entries as $entry) {
            $this->assertSame(EntryStatus::Refunded, $entry->refresh()->status);
        }

        // Refund record created
        $refund = Refund::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($order->total_cents, $refund->amount_cents);
        $this->assertSame('PEN', $refund->currency);
        $this->assertSame(self::REASON, $refund->reason);
        $this->assertSame($admin->id, $refund->processed_by_user_id);

        // Audit game_event created
        $this->assertSame(1, GameEvent::query()
            ->where('game_id', $game->id)
            ->where('type', GameEventType::OrderRefunded)
            ->count());

        // Domain event dispatched
        Event::assertDispatched(OrderRefunded::class, function (OrderRefunded $e) use ($order, $payment, $game, $admin): bool {
            $numbers = $e->numbers;
            sort($numbers);

            return $e->orderId === $order->id
                && $e->paymentId === $payment->id
                && $e->gameId === $game->id
                && $e->actorUserId === $admin->id
                && $e->refundedCents === $order->total_cents
                && $e->currency === 'PEN'
                && $numbers === [1, 2];
        });
    }

    public function test_idempotent_replay_same_key_same_payload_returns_200(): void
    {
        Event::fake([OrderRefunded::class]);
        [, $admin, , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        $payload = ['reason' => self::REASON];
        $headers = ['Idempotency-Key' => self::KEY_A];

        $first = $this->postJson("/api/v1/admin/orders/{$order->id}/refund", $payload, $headers);
        $second = $this->postJson("/api/v1/admin/orders/{$order->id}/refund", $payload, $headers);

        $first->assertOk();
        $second->assertOk();

        // Same refund ID returned
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        // Only one Refund record in DB
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());

        // Domain event dispatched only once (idempotent replay does not re-dispatch)
        Event::assertDispatchedTimes(OrderRefunded::class, 1);
    }

    public function test_idempotency_key_conflict_same_key_different_reason_returns_409(): void
    {
        Event::fake([OrderRefunded::class]);
        [, $admin, , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        $headers = ['Idempotency-Key' => self::KEY_A];

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], $headers)->assertOk();

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => 'Una razón completamente diferente para el reembolso.',
        ], $headers)->assertStatus(409)->assertJsonPath('error', 'idempotency_key_mismatch');
    }

    public function test_different_key_after_refund_returns_existing_refund(): void
    {
        Event::fake([OrderRefunded::class]);
        [, $admin, , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        $first = $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();

        $second = $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_B])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
    }

    public function test_order_not_paid_returns_422_order_not_refundable(): void
    {
        [, $admin, $game] = $this->setupPaidOrder();

        $buyer = User::factory()->create();
        $pendingOrder = Order::create([
            'user_id' => $buyer->id,
            'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 1000,
            'total_cents' => 1000,
            'currency' => 'PEN',
            'expires_at' => now()->addMinutes(10),
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/orders/{$pendingOrder->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertStatus(422)
            ->assertJsonPath('error', 'order_not_refundable')
            ->assertJsonPath('reason', 'order_not_paid');
    }

    public function test_payment_not_approved_returns_422_order_not_refundable(): void
    {
        [, $admin, , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        // Force payment back to UnderReview to simulate mismatched state
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $payment->forceFill(['status' => PaymentStatus::UnderReview])->saveQuietly();

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertStatus(422)
            ->assertJsonPath('error', 'order_not_refundable')
            ->assertJsonPath('reason', 'payment_not_approved');
    }

    public function test_game_in_running_status_returns_422_order_not_refundable(): void
    {
        [, $admin, , $order] = $this->setupPaidOrder(gameStatus: GameStatus::Running);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertStatus(422)
            ->assertJsonPath('error', 'order_not_refundable')
            ->assertJsonPath('reason', 'game_not_refundable');
    }

    public function test_entry_is_winner_returns_422_winner_entry_not_refundable(): void
    {
        [, $admin, , $order, , , $entries] = $this->setupPaidOrder(numberCount: 1);
        Sanctum::actingAs($admin);

        // Use transitionTo to satisfy the GameEntry immutability guard
        $entries[0]->transitionTo(EntryStatus::Winner);
        $entries[0]->save();

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertStatus(422)
            ->assertJsonPath('error', 'winner_entry_not_refundable');
    }

    public function test_game_winner_references_order_entry_returns_422(): void
    {
        [, $admin, $game, $order, , $gameNumbers, $entries] = $this->setupPaidOrder(numberCount: 1);
        Sanctum::actingAs($admin);

        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $gameNumbers[0]->id,
            'sequence' => 1,
            'drawn_number' => $gameNumbers[0]->number,
            'drawn_at' => now(),
            'strategy' => 'crypto_secure',
        ]);

        GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entries[0]->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $gameNumbers[0]->id,
            'user_id' => $entries[0]->user_id,
            'winning_hits' => 1,
            'won_at' => now(),
        ]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertStatus(422)
            ->assertJsonPath('error', 'winner_entry_not_refundable');
    }

    public function test_refund_works_for_sales_closed_game(): void
    {
        Event::fake([OrderRefunded::class]);
        [, $admin, , $order] = $this->setupPaidOrder(gameStatus: GameStatus::SalesClosed);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();
    }

    public function test_refund_works_for_cancelled_game(): void
    {
        Event::fake([OrderRefunded::class]);
        [, $admin, , $order] = $this->setupPaidOrder(gameStatus: GameStatus::Cancelled);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();
    }

    public function test_response_does_not_expose_sensitive_fields(): void
    {
        Event::fake([OrderRefunded::class]);
        [, $admin, , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        $data = $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('idempotency_key_hash', $data);
        $this->assertArrayNotHasKey('request_fingerprint', $data);
        $this->assertArrayNotHasKey('buyer_user_id', $data);
    }

    // ── GET /api/v1/admin/orders/{order}/refund ───────────────────────────────

    public function test_get_unauthenticated_returns_401(): void
    {
        [, , , $order] = $this->setupPaidOrder();

        $this->getJson("/api/v1/admin/orders/{$order->id}/refund")->assertStatus(401);
    }

    public function test_get_player_returns_403(): void
    {
        [$buyer, , , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($buyer);

        $this->getJson("/api/v1/admin/orders/{$order->id}/refund")->assertStatus(403);
    }

    public function test_get_refund_not_found_returns_404(): void
    {
        [, $admin, , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/orders/{$order->id}/refund")
            ->assertStatus(404)
            ->assertJsonPath('error', 'refund_not_found');
    }

    public function test_get_refund_returns_200_with_correct_resource(): void
    {
        Event::fake([OrderRefunded::class]);
        [, $admin, $game, $order, $payment] = $this->setupPaidOrder(numberCount: 2);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();

        $response = $this->getJson("/api/v1/admin/orders/{$order->id}/refund");
        $response->assertOk();
        $response->assertJsonPath('data.order_id', $order->id);
        $response->assertJsonPath('data.payment_id', $payment->id);
        $response->assertJsonPath('data.game_id', $game->id);
        $response->assertJsonPath('data.amount_cents', $order->total_cents);
        $response->assertJsonPath('data.currency', 'PEN');
        $response->assertJsonPath('data.reason', self::REASON);
        $response->assertJsonPath('data.entries.count', 2);
        $this->assertSame([1, 2], $response->json('data.numbers'));
    }

    public function test_get_refund_does_not_expose_sensitive_fields(): void
    {
        Event::fake([OrderRefunded::class]);
        [, $admin, , $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A]);

        $data = $this->getJson("/api/v1/admin/orders/{$order->id}/refund")
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('idempotency_key_hash', $data);
        $this->assertArrayNotHasKey('request_fingerprint', $data);
    }

    // ── Audit ─────────────────────────────────────────────────────────────────

    public function test_game_event_has_order_refunded_type_and_does_not_contain_secrets(): void
    {
        Event::fake([OrderRefunded::class]);
        [, $admin, $game, $order] = $this->setupPaidOrder();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'reason' => self::REASON,
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();

        $event = GameEvent::query()
            ->where('game_id', $game->id)
            ->where('type', GameEventType::OrderRefunded)
            ->firstOrFail();

        $payload = $event->payload;
        $this->assertSame($order->id, $payload['order_id']);
        $this->assertSame($admin->id, $payload['actor_user_id']);
        $this->assertSame($order->total_cents, $payload['refunded_cents']);
        $this->assertSame('PEN', $payload['currency']);
        $this->assertSame(self::REASON, $payload['refund_reason']);
        $this->assertArrayNotHasKey('idempotency_key_hash', $payload);
        $this->assertArrayNotHasKey('request_fingerprint', $payload);
    }
}
