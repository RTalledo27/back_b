<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\PaymentApproved;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameNumbersSold;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ApprovePaymentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY_A = 'approve-key-aaaaaaaaaaaaaaaa';

    private const KEY_B = 'approve-key-bbbbbbbbbbbbbbbb';

    /**
     * @return array{User, User, Order, Payment, list<GameNumber>}
     */
    private function setupUnderReviewOrder(int $numberCount = 2): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $game = Game::create([
            'slug' => 'ap-'.fake()->unique()->lexify('?????'),
            'name' => 'A',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::SalesOpen,
        ]);

        $gns = [];
        for ($i = 1; $i <= $numberCount; $i++) {
            $gns[] = GameNumber::create([
                'game_id' => $game->id, 'number' => $i,
                'status' => GameNumberStatus::Reserved,
            ]);
        }

        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::PaymentSubmitted,
            'subtotal_cents' => 500 * $numberCount,
            'total_cents' => 500 * $numberCount,
            'currency' => 'PEN', 'expires_at' => null,
        ]);

        foreach ($gns as $gn) {
            OrderItem::create([
                'order_id' => $order->id,
                'game_number_id' => $gn->id,
                'unit_price_cents' => 500,
            ]);
            NumberReservation::create([
                'order_id' => $order->id,
                'game_number_id' => $gn->id,
            ]);
        }

        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500 * $numberCount,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::UnderReview,
            'submitted_at' => now()->subMinute(),
        ]);

        return [$buyer, $admin, $order, $payment, $gns];
    }

    public function test_unauthenticated_returns_401(): void
    {
        [, , , $payment] = $this->setupUnderReviewOrder();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", [], [
            'Idempotency-Key' => self::KEY_A,
        ])->assertStatus(401);
    }

    public function test_non_admin_returns_403(): void
    {
        [$buyer, , , $payment] = $this->setupUnderReviewOrder();
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", [], [
            'Idempotency-Key' => self::KEY_A,
        ])->assertStatus(403);
    }

    public function test_missing_idempotency_key_returns_400(): void
    {
        [, $admin, , $payment] = $this->setupUnderReviewOrder();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve")->assertStatus(400);
    }

    public function test_approve_creates_entries_allocations_transitions_numbers_and_audits(): void
    {
        Event::fake([PaymentApproved::class, GameNumbersSold::class]);
        [$buyer, $admin, $order, $payment, $gns] = $this->setupUnderReviewOrder(numberCount: 2);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", [
            'notes' => 'verified bank statement',
        ], ['Idempotency-Key' => self::KEY_A]);

        $response->assertOk();
        $response->assertJsonPath('data.payment.status', 'approved');
        $response->assertJsonPath('data.order.status', 'paid');
        $response->assertJsonPath('data.entries.count', 2);

        $payment->refresh();
        $order->refresh();
        $this->assertSame(PaymentStatus::Approved, $payment->status);
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($payment->reviewed_at);
        $this->assertSame($admin->id, $payment->reviewed_by);

        foreach ($gns as $gn) {
            $this->assertSame(GameNumberStatus::Sold, $gn->refresh()->status);
        }

        // Entries + allocations: one per item
        $this->assertSame(2, GameEntry::query()->where('game_id', $order->game_id)->count());
        $this->assertSame(2, PurchaseAllocation::query()->where('payment_id', $payment->id)->count());

        // Each entry corresponds to a sold number, owned by the buyer
        foreach (GameEntry::query()->where('game_id', $order->game_id)->get() as $entry) {
            $this->assertSame($buyer->id, $entry->user_id);
            $this->assertSame(EntryStatus::Confirmed, $entry->status);
        }

        // Reservations were deleted
        $this->assertSame(0, NumberReservation::query()->where('order_id', $order->id)->count());

        // Audit
        $this->assertTrue(
            GameEvent::query()->where('game_id', $order->game_id)
                ->where('type', GameEventType::PaymentApproved)->exists()
        );
        $this->assertTrue(
            GameEvent::query()->where('game_id', $order->game_id)
                ->where('type', GameEventType::NumberSold)->exists()
        );

        Event::assertDispatched(PaymentApproved::class);
        Event::assertDispatched(GameNumbersSold::class);
    }

    public function test_replay_same_key_returns_same_result_without_duplicating_entries(): void
    {
        [$buyer, $admin, $order, $payment] = $this->setupUnderReviewOrder(2);
        Sanctum::actingAs($admin);

        $first = $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", [], [
            'Idempotency-Key' => self::KEY_A,
        ])->assertOk();

        $second = $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", [], [
            'Idempotency-Key' => self::KEY_A,
        ])->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(2, GameEntry::query()->where('game_id', $order->game_id)->count());
        $this->assertSame(2, PurchaseAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $order->game_id)
                ->where('type', GameEventType::PaymentApproved)->count(),
            'Audit must not be duplicated on idempotent replay.'
        );
    }

    public function test_new_key_after_approval_returns_existing_state_without_duplicate_audit(): void
    {
        [$buyer, $admin, $order, $payment] = $this->setupUnderReviewOrder(2);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", [], [
            'Idempotency-Key' => self::KEY_A,
        ])->assertOk();

        // Different key, payment already Approved → action returns existing state
        $second = $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", [], [
            'Idempotency-Key' => self::KEY_B,
        ])->assertOk();

        $second->assertJsonPath('data.payment.status', 'approved');
        $this->assertSame(2, GameEntry::query()->where('game_id', $order->game_id)->count());
        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $order->game_id)
                ->where('type', GameEventType::PaymentApproved)->count(),
        );
    }

    public function test_approve_fails_when_payment_is_pending(): void
    {
        [, $admin, , $payment] = $this->setupUnderReviewOrder();
        Sanctum::actingAs($admin);

        // Roll payment back to pending (no evidence yet)
        $payment->status = PaymentStatus::Pending;
        $payment->submitted_at = null;
        $payment->saveQuietly();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", [], [
            'Idempotency-Key' => self::KEY_A,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_payment_transition');
    }

    public function test_approve_fails_when_payment_already_rejected(): void
    {
        [, $admin, $order, $payment] = $this->setupUnderReviewOrder();
        Sanctum::actingAs($admin);

        $payment->status = PaymentStatus::Rejected;
        $payment->saveQuietly();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/approve", [], [
            'Idempotency-Key' => self::KEY_A,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_payment_transition');
    }
}
