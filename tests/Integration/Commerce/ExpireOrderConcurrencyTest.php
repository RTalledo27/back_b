<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Actions\ApprovePaymentAction;
use App\Modules\Commerce\Application\Actions\ExpireOrderAction;
use App\Modules\Commerce\Application\Actions\RejectPaymentAction;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentData;
use App\Modules\Commerce\Application\DTOs\ExpireOrderData;
use App\Modules\Commerce\Application\DTOs\ExpireOrderOutcome;
use App\Modules\Commerce\Application\DTOs\RejectPaymentData;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
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
use Tests\TestCase;

/**
 * The Order → Payment canonical lock order is what prevents deadlocks
 * between approve/reject and expire when they happen to target the same
 * order. These tests run the two operations in sequence (the lock graph
 * is acyclic, so true concurrent execution still serialises cleanly via
 * Postgres row locks; verifying the lock graph itself is done by
 * PaymentActionsLockOrderTest).
 */
final class ExpireOrderConcurrencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{User, User, Order, Payment, GameNumber}
     */
    private function setupUnderReviewOverdue(): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $game = Game::create([
            'slug' => 'ec-'.fake()->unique()->lexify('?????'),
            'name' => 'EC', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesOpen,
        ]);
        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => 1,
            'status' => GameNumberStatus::Reserved,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::PaymentSubmitted,
            'subtotal_cents' => 500, 'total_cents' => 500,
            'currency' => 'PEN', 'expires_at' => null, // submitted orders never expire
        ]);
        OrderItem::create(['order_id' => $order->id, 'game_number_id' => $gn->id, 'unit_price_cents' => 500]);
        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $gn->id]);
        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::UnderReview, 'submitted_at' => now()->subMinute(),
        ]);

        return [$buyer, $admin, $order, $payment, $gn];
    }

    public function test_approve_then_expire_leaves_paid_state_intact(): void
    {
        [, $admin, $order, $payment, $gn] = $this->setupUnderReviewOverdue();

        // Approve (payment_submitted → paid)
        $this->app->make(ApprovePaymentAction::class)->execute(new ApprovePaymentData(
            paymentId: $payment->id, reviewerUserId: $admin->id,
        ));

        // Force expires_at into the past to *try* expiring an already-paid order.
        // The batch query filters by status=pending so it would not even examine it;
        // calling the per-order action returns SkippedStateChanged.
        $order->expires_at = now()->subMinute();
        $order->saveQuietly();

        $result = $this->app->make(ExpireOrderAction::class)
            ->execute(new ExpireOrderData($order->id));

        $this->assertSame(ExpireOrderOutcome::SkippedStateChanged, $result->outcome);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(GameNumberStatus::Sold, $gn->refresh()->status);
    }

    public function test_reject_then_expire_leaves_rejected_state_intact(): void
    {
        [, $admin, $order, $payment, $gn] = $this->setupUnderReviewOverdue();

        $this->app->make(RejectPaymentAction::class)->execute(new RejectPaymentData(
            paymentId: $payment->id, reviewerUserId: $admin->id, reason: 'bad evidence',
        ));

        $order->expires_at = now()->subMinute();
        $order->saveQuietly();

        $result = $this->app->make(ExpireOrderAction::class)
            ->execute(new ExpireOrderData($order->id));

        $this->assertSame(ExpireOrderOutcome::SkippedStateChanged, $result->outcome);
        $this->assertSame(OrderStatus::Rejected, $order->refresh()->status);
        $this->assertSame(PaymentStatus::Rejected, $payment->refresh()->status);
        $this->assertSame(GameNumberStatus::Available, $gn->refresh()->status);
    }

    public function test_double_expire_of_same_order_is_safe(): void
    {
        // Pending overdue order (no payment_submitted yet).
        $buyer = User::factory()->create();
        $game = Game::create([
            'slug' => 'de-'.fake()->unique()->lexify('?????'),
            'name' => 'DE', 'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesOpen,
        ]);
        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => 1,
            'status' => GameNumberStatus::Reserved,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500, 'total_cents' => 500,
            'currency' => 'PEN', 'expires_at' => now()->subMinute(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'game_number_id' => $gn->id, 'unit_price_cents' => 500]);
        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $gn->id]);
        Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);

        $first = $this->app->make(ExpireOrderAction::class)->execute(new ExpireOrderData($order->id));
        $second = $this->app->make(ExpireOrderAction::class)->execute(new ExpireOrderData($order->id));

        $this->assertSame(ExpireOrderOutcome::Expired, $first->outcome);
        $this->assertSame(ExpireOrderOutcome::AlreadyExpired, $second->outcome);

        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::ReservationExpired)->count(),
        );
    }

    public function test_batch_query_does_not_include_payment_submitted_orders(): void
    {
        // payment_submitted has expires_at = NULL by contract, but even if a
        // bug stored a past timestamp the batch's status filter must hide it.
        [, , $order] = $this->setupUnderReviewOverdue();
        DB::table('orders')->where('id', $order->id)
            ->update(['expires_at' => now()->subMinute()]);

        $candidateIds = Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->pluck('id')
            ->all();

        $this->assertNotContains($order->id, $candidateIds);
    }
}
