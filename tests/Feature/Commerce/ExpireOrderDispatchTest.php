<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Actions\ExpireOrderAction;
use App\Modules\Commerce\Application\Actions\ExpirePendingOrdersAction;
use App\Modules\Commerce\Application\DTOs\ExpireOrderData;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies the post-2.5-corrections contract:
 *  - ExpireOrderAction::execute() emits the event itself (centralised).
 *  - ExpirePendingOrdersAction does NOT redispatch.
 *  - A listener failure does not roll back the expired state.
 *  - A failing order in the batch reports the exception (with safe
 *    context) and the next order is still processed.
 */
final class ExpireOrderDispatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{User, Order, Payment, list<GameNumber>}
     */
    private function setupOverduePending(int $count = 1): array
    {
        $buyer = User::factory()->create();
        $game = Game::create([
            'slug' => 'edx-'.fake()->unique()->lexify('?????'),
            'name' => 'X',
            'number_min' => 1, 'number_max' => 20, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesOpen,
        ]);
        $gns = [];
        for ($i = 1; $i <= $count; $i++) {
            $gns[] = GameNumber::create([
                'game_id' => $game->id,
                'number' => $i,
                'status' => GameNumberStatus::Reserved,
            ]);
        }
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500 * $count, 'total_cents' => 500 * $count,
            'currency' => 'PEN', 'expires_at' => now()->subMinute(),
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
            'order_id' => $order->id, 'amount_cents' => 500 * $count,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);

        return [$buyer, $order, $payment, $gns];
    }

    public function test_direct_execute_dispatches_event_exactly_once(): void
    {
        Event::fake([OrderReservationsExpired::class]);
        [, $order] = $this->setupOverduePending();

        $this->app->make(ExpireOrderAction::class)
            ->execute(new ExpireOrderData($order->id));

        Event::assertDispatched(OrderReservationsExpired::class, 1);
    }

    public function test_direct_execute_repeated_does_not_redispatch(): void
    {
        Event::fake([OrderReservationsExpired::class]);
        [, $order] = $this->setupOverduePending();

        $action = $this->app->make(ExpireOrderAction::class);
        $action->execute(new ExpireOrderData($order->id));
        $action->execute(new ExpireOrderData($order->id));

        Event::assertDispatched(OrderReservationsExpired::class, 1);
    }

    public function test_batch_path_dispatches_event_exactly_once_via_action(): void
    {
        Event::fake([OrderReservationsExpired::class]);
        $this->setupOverduePending();
        $this->setupOverduePending();

        $metrics = $this->app->make(ExpirePendingOrdersAction::class)->execute();

        $this->assertSame(2, $metrics['expired']);
        Event::assertDispatched(OrderReservationsExpired::class, 2);
    }

    public function test_listener_failure_preserves_expired_state(): void
    {
        Exceptions::fake();
        Event::listen(OrderReservationsExpired::class, function (): void {
            throw new RuntimeException('listener exploded after commit');
        });

        [, $order, $payment, $gns] = $this->setupOverduePending(2);

        $this->app->make(ExpireOrderAction::class)
            ->execute(new ExpireOrderData($order->id));

        Exceptions::assertReported(RuntimeException::class);

        $this->assertSame(OrderStatus::Expired, $order->refresh()->status);
        $this->assertSame(PaymentStatus::Cancelled, $payment->refresh()->status);
        foreach ($gns as $gn) {
            $this->assertSame(GameNumberStatus::Available, $gn->refresh()->status);
        }
        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $order->game_id)
                ->where('type', GameEventType::ReservationExpired)->count(),
        );
    }

    public function test_corrupted_order_reports_exception_and_next_order_still_processes(): void
    {
        Exceptions::fake();
        Log::spy();

        [, $cleanOrder] = $this->setupOverduePending(1);
        [, $corruptedOrder] = $this->setupOverduePending(2);

        // Corrupt the second order by deleting one of its reservations.
        NumberReservation::query()
            ->where('order_id', $corruptedOrder->id)
            ->orderBy('id')
            ->limit(1)
            ->delete();

        $metrics = $this->app->make(ExpirePendingOrdersAction::class)->execute();

        $this->assertSame(2, $metrics['examined']);
        $this->assertSame(1, $metrics['expired']);
        $this->assertSame(1, $metrics['failed']);
        $this->assertSame(0, $metrics['skipped']);

        Exceptions::assertReported(OrderExpirationIntegrityError::class);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'Order expiration failed')
                    && ($context['phase'] ?? null) === 'commerce_expiration'
                    && isset($context['order_id'])
                    && isset($context['exception'])
                    && ! isset($context['email'])
                    && ! isset($context['name']);
            })
            ->atLeast()->once();

        // The clean order still expired.
        $this->assertSame(OrderStatus::Expired, $cleanOrder->refresh()->status);
        // The corrupted order was rolled back and remains pending for ops to fix.
        $this->assertSame(OrderStatus::Pending, $corruptedOrder->refresh()->status);
    }
}
