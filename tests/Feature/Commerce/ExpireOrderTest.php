<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Actions\ExpireOrderAction;
use App\Modules\Commerce\Application\Actions\ExpirePendingOrdersAction;
use App\Modules\Commerce\Application\DTOs\ExpireOrderData;
use App\Modules\Commerce\Application\DTOs\ExpireOrderOutcome;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\OrderReservationsExpired;
use App\Modules\Commerce\Domain\Exceptions\OrderExpirationIntegrityError;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class ExpireOrderTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{User, Order, Payment, list<GameNumber>}
     */
    private function setupPendingOrder(
        int $numberCount = 2,
        Carbon|string|null $expiresAt = 'past',
        OrderStatus $orderStatus = OrderStatus::Pending,
        PaymentStatus $paymentStatus = PaymentStatus::Pending,
        GameNumberStatus $numberStatus = GameNumberStatus::Reserved,
    ): array {
        $buyer = User::factory()->create();
        $game = Game::create([
            'slug' => 'ex-'.fake()->unique()->lexify('?????'),
            'name' => 'X',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesOpen,
        ]);

        $gns = [];
        for ($i = 1; $i <= $numberCount; $i++) {
            $gns[] = GameNumber::create([
                'game_id' => $game->id, 'number' => $i,
                'status' => $numberStatus,
            ]);
        }

        $resolvedExpiresAt = match (true) {
            $expiresAt === 'past' => now()->subMinute(),
            $expiresAt === 'future' => now()->addHour(),
            $expiresAt === null => null,
            default => $expiresAt,
        };

        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => $orderStatus,
            'subtotal_cents' => 500 * $numberCount,
            'total_cents' => 500 * $numberCount,
            'currency' => 'PEN', 'expires_at' => $resolvedExpiresAt,
        ]);

        foreach ($gns as $gn) {
            OrderItem::create([
                'order_id' => $order->id, 'game_number_id' => $gn->id,
                'unit_price_cents' => 500,
            ]);
            NumberReservation::create([
                'order_id' => $order->id, 'game_number_id' => $gn->id,
            ]);
        }

        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500 * $numberCount,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => $paymentStatus,
        ]);

        return [$buyer, $order, $payment, $gns];
    }

    public function test_expires_a_pending_overdue_order_atomically(): void
    {
        [$buyer, $order, $payment, $gns] = $this->setupPendingOrder(2);
        $originalExpiresAt = $order->expires_at;

        $result = $this->app->make(ExpireOrderAction::class)
            ->execute(new ExpireOrderData(orderId: $order->id));

        $this->assertSame(ExpireOrderOutcome::Expired, $result->outcome);
        $this->assertNotNull($result->expiredAt);

        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::Expired, $order->status);
        $this->assertSame(PaymentStatus::Cancelled, $payment->status);
        $this->assertNotNull($order->expired_at);
        $this->assertSame(
            $originalExpiresAt->toIso8601String(),
            $order->expires_at->toIso8601String(),
            'expires_at must be preserved for traceability.',
        );

        foreach ($gns as $gn) {
            $this->assertSame(GameNumberStatus::Available, $gn->refresh()->status);
        }
        $this->assertSame(0, NumberReservation::query()->where('order_id', $order->id)->count());

        $audits = GameEvent::query()->where('game_id', $order->game_id)
            ->where('type', GameEventType::ReservationExpired)->get();
        $this->assertCount(1, $audits, 'A single aggregated audit row must be written.');
        $payload = $audits->first()->payload;
        $this->assertSame($order->id, $payload['order_id']);
        $this->assertSame([1, 2], $payload['numbers']);
        $this->assertSame($originalExpiresAt->toIso8601String(), $payload['scheduled_expiration_at']);
    }

    public function test_running_twice_does_not_duplicate_audit_or_events(): void
    {
        Event::fake([OrderReservationsExpired::class]);
        [, $order] = $this->setupPendingOrder(2);

        $batch = $this->app->make(ExpirePendingOrdersAction::class);

        $first = $batch->execute();
        $second = $batch->execute();

        $this->assertSame(1, $first['expired']);
        $this->assertSame(0, $second['expired']);
        $this->assertSame(0, $second['examined'], 'Already-expired orders no longer match the batch query.');

        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $order->game_id)
                ->where('type', GameEventType::ReservationExpired)->count(),
        );
        Event::assertDispatched(OrderReservationsExpired::class, 1);
    }

    public function test_already_expired_order_is_no_op(): void
    {
        [, $order] = $this->setupPendingOrder(1);
        $this->app->make(ExpireOrderAction::class)->execute(new ExpireOrderData($order->id));

        $result = $this->app->make(ExpireOrderAction::class)
            ->execute(new ExpireOrderData($order->id));

        $this->assertSame(ExpireOrderOutcome::AlreadyExpired, $result->outcome);
        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $order->game_id)
                ->where('type', GameEventType::ReservationExpired)->count(),
        );
    }

    public function test_order_not_due_is_not_modified(): void
    {
        [, $order, $payment, $gns] = $this->setupPendingOrder(1, expiresAt: 'future');

        $result = $this->app->make(ExpireOrderAction::class)
            ->execute(new ExpireOrderData($order->id));

        $this->assertSame(ExpireOrderOutcome::NotDue, $result->outcome);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(GameNumberStatus::Reserved, $gns[0]->refresh()->status);
    }

    public function test_payment_submitted_order_is_not_touched(): void
    {
        [, $order, $payment, $gns] = $this->setupPendingOrder(
            numberCount: 1,
            expiresAt: null,
            orderStatus: OrderStatus::PaymentSubmitted,
            paymentStatus: PaymentStatus::UnderReview,
        );

        $batch = $this->app->make(ExpirePendingOrdersAction::class);
        $metrics = $batch->execute();

        $this->assertSame(0, $metrics['examined'],
            'Batch query must exclude payment_submitted orders by construction.');
        $this->assertSame(OrderStatus::PaymentSubmitted, $order->refresh()->status);
        $this->assertSame(PaymentStatus::UnderReview, $payment->refresh()->status);
        $this->assertSame(GameNumberStatus::Reserved, $gns[0]->refresh()->status);
    }

    public function test_paid_or_rejected_or_cancelled_orders_are_not_touched(): void
    {
        foreach ([OrderStatus::Paid, OrderStatus::Rejected, OrderStatus::Cancelled] as $terminal) {
            [, $order] = $this->setupPendingOrder(
                numberCount: 1,
                expiresAt: 'past',
                orderStatus: $terminal,
                paymentStatus: PaymentStatus::Cancelled,
                numberStatus: GameNumberStatus::Available,
            );

            $result = $this->app->make(ExpireOrderAction::class)
                ->execute(new ExpireOrderData($order->id));

            $this->assertSame(ExpireOrderOutcome::SkippedStateChanged, $result->outcome);
            $this->assertSame($terminal, $order->refresh()->status);
        }
    }

    public function test_missing_reservation_triggers_full_rollback(): void
    {
        [, $order, , $gns] = $this->setupPendingOrder(2);

        NumberReservation::query()->where('order_id', $order->id)
            ->where('game_number_id', $gns[0]->id)->delete();

        try {
            $this->app->make(ExpireOrderAction::class)
                ->execute(new ExpireOrderData($order->id));
            $this->fail('Expected integrity error.');
        } catch (OrderExpirationIntegrityError) {
            // expected
        }

        // Rollback: order/payment/numbers untouched.
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        foreach ($gns as $gn) {
            $this->assertSame(GameNumberStatus::Reserved, $gn->refresh()->status);
        }
    }

    public function test_number_not_reserved_triggers_full_rollback(): void
    {
        [, $order, , $gns] = $this->setupPendingOrder(2);

        // Force one number out of Reserved (simulate inconsistency).
        $gns[0]->transitionTo(GameNumberStatus::Available);
        $gns[0]->save();

        $this->expectException(OrderExpirationIntegrityError::class);

        try {
            $this->app->make(ExpireOrderAction::class)
                ->execute(new ExpireOrderData($order->id));
        } finally {
            // The other number must still be Reserved (no partial release).
            $this->assertSame(GameNumberStatus::Reserved, $gns[1]->refresh()->status);
            $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        }
    }

    public function test_multiple_numbers_release_atomically(): void
    {
        [, $order, , $gns] = $this->setupPendingOrder(5);

        $result = $this->app->make(ExpireOrderAction::class)
            ->execute(new ExpireOrderData($order->id));

        $this->assertSame(ExpireOrderOutcome::Expired, $result->outcome);
        $this->assertCount(5, $result->numbers);
        foreach ($gns as $gn) {
            $this->assertSame(GameNumberStatus::Available, $gn->refresh()->status);
        }
        $this->assertSame(0, NumberReservation::query()->where('order_id', $order->id)->count());
    }

    public function test_batch_returns_metrics_for_mix_of_outcomes(): void
    {
        // One overdue order
        [, $orderOverdue] = $this->setupPendingOrder(1, expiresAt: 'past');
        // One pending but not yet due
        [, $orderFuture] = $this->setupPendingOrder(1, expiresAt: 'future');
        // One already paid (won't match batch query)
        [, $orderPaid] = $this->setupPendingOrder(
            numberCount: 1, expiresAt: 'past',
            orderStatus: OrderStatus::Paid,
            paymentStatus: PaymentStatus::Approved,
            numberStatus: GameNumberStatus::Sold,
        );

        $metrics = $this->app->make(ExpirePendingOrdersAction::class)->execute();

        $this->assertSame(1, $metrics['examined'], 'Only the overdue pending order matches.');
        $this->assertSame(1, $metrics['expired']);
        $this->assertSame(0, $metrics['skipped']);
        $this->assertSame(0, $metrics['failed']);

        $this->assertSame(OrderStatus::Expired, $orderOverdue->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $orderFuture->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $orderPaid->refresh()->status);
    }
}
