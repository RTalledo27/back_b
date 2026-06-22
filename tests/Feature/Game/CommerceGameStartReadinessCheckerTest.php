<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\GameLifecycle\CommerceGameStartReadinessChecker;
use App\Modules\RepeatNumberBingo\Application\Contracts\GameStartReadinessChecker;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Exceptions\GameNotReadyForStart;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\ValueObjects\GameStartReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Uses DatabaseTruncation (not RefreshDatabase) so that
 * test_throws_logic_exception_outside_transaction runs with
 * DB::transactionLevel() === 0 — the wrapping test transaction that
 * RefreshDatabase would open hides the bug we are trying to assert.
 */
final class CommerceGameStartReadinessCheckerTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    private function makeGameSalesClosed(): Game
    {
        return Game::create([
            'slug' => 'rdy-'.fake()->unique()->lexify('?????'),
            'name' => 'R', 'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesClosed,
            'scheduled_start_at' => now()->addHour(),
        ]);
    }

    private function makeConfirmedEntry(Game $game, int $number = 1): GameEntry
    {
        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => $number, 'status' => GameNumberStatus::Sold,
        ]);

        return GameEntry::create([
            'game_id' => $game->id, 'game_number_id' => $gn->id,
            'user_id' => User::factory()->create()->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    private function makeOrder(Game $game, OrderStatus $orderStatus, PaymentStatus $paymentStatus): Order
    {
        $buyer = User::factory()->create();
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => $orderStatus,
            'subtotal_cents' => 500, 'total_cents' => 500,
            'currency' => 'PEN',
            'expires_at' => $orderStatus === OrderStatus::Pending ? now()->addHour() : null,
        ]);
        Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => $paymentStatus,
            'submitted_at' => $paymentStatus === PaymentStatus::UnderReview ? now() : null,
        ]);

        return $order;
    }

    /**
     * @param  callable(Game): void  $arrange
     * @param  list<string>  $expectedReasons
     */
    private function assertReasons(callable $arrange, array $expectedReasons): void
    {
        $game = $this->makeGameSalesClosed();
        $arrange($game);

        try {
            DB::transaction(function () use ($game): void {
                Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
                $this->app->make(GameStartReadinessChecker::class)->assertReadyForStart($game->id);
            });
            $this->fail('Expected GameNotReadyForStart.');
        } catch (GameNotReadyForStart $e) {
            foreach ($expectedReasons as $reason) {
                $this->assertContains($reason, $e->reasons, "Expected reason '$reason' in: ".implode(', ', $e->reasons));
            }
        }
    }

    public function test_fails_with_pending_order(): void
    {
        $this->assertReasons(
            function (Game $game): void {
                $this->makeConfirmedEntry($game, 1);
                $this->makeOrder($game, OrderStatus::Pending, PaymentStatus::Pending);
            },
            ['has_pending_orders', 'has_pending_payments'],
        );
    }

    public function test_fails_with_payment_submitted_order(): void
    {
        $this->assertReasons(
            function (Game $game): void {
                $this->makeConfirmedEntry($game, 1);
                $this->makeOrder($game, OrderStatus::PaymentSubmitted, PaymentStatus::UnderReview);
            },
            ['has_payment_submitted_orders', 'has_under_review_payments'],
        );
    }

    public function test_fails_with_active_reservation(): void
    {
        $this->assertReasons(
            function (Game $game): void {
                $this->makeConfirmedEntry($game, 1);
                $order = $this->makeOrder($game, OrderStatus::Pending, PaymentStatus::Pending);
                $gn = GameNumber::create([
                    'game_id' => $game->id, 'number' => 7, 'status' => GameNumberStatus::Reserved,
                ]);
                OrderItem::create(['order_id' => $order->id, 'game_number_id' => $gn->id, 'unit_price_cents' => 500]);
                NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $gn->id]);
            },
            ['has_active_reservations', 'has_reserved_numbers'],
        );
    }

    public function test_fails_without_confirmed_entries(): void
    {
        $this->assertReasons(
            fn (Game $game) => null,
            ['no_confirmed_entries'],
        );
    }

    public function test_collects_all_applicable_reasons(): void
    {
        $game = $this->makeGameSalesClosed();
        $order = $this->makeOrder($game, OrderStatus::Pending, PaymentStatus::Pending);
        $gn = GameNumber::create(['game_id' => $game->id, 'number' => 7, 'status' => GameNumberStatus::Reserved]);
        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $gn->id]);

        try {
            DB::transaction(function () use ($game): void {
                Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
                $this->app->make(GameStartReadinessChecker::class)->assertReadyForStart($game->id);
            });
            $this->fail('Expected GameNotReadyForStart.');
        } catch (GameNotReadyForStart $e) {
            foreach (['has_pending_orders', 'has_pending_payments', 'has_active_reservations', 'has_reserved_numbers', 'no_confirmed_entries'] as $reason) {
                $this->assertContains($reason, $e->reasons);
            }
        }
    }

    public function test_returns_readiness_when_ready(): void
    {
        $game = $this->makeGameSalesClosed();
        $this->makeConfirmedEntry($game, 1);
        $this->makeConfirmedEntry($game, 2);

        $readiness = DB::transaction(function () use ($game): GameStartReadiness {
            Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();

            return $this->app->make(GameStartReadinessChecker::class)->assertReadyForStart($game->id);
        });

        $this->assertSame(2, $readiness->confirmedEntriesCount);
        $this->assertInstanceOf(CarbonImmutable::class, $readiness->verifiedAt);
    }

    public function test_throws_logic_exception_outside_transaction(): void
    {
        $game = $this->makeGameSalesClosed();
        $this->makeConfirmedEntry($game, 1);

        $this->expectException(LogicException::class);
        $this->app->make(GameStartReadinessChecker::class)->assertReadyForStart($game->id);
    }

    public function test_does_not_mutate_any_row(): void
    {
        $game = $this->makeGameSalesClosed();
        $this->makeConfirmedEntry($game, 1);
        $order = $this->makeOrder($game, OrderStatus::Pending, PaymentStatus::Pending);

        $orderStatusBefore = $order->status->value;
        $orderUpdatedAtBefore = $order->updated_at?->toIso8601String();
        $gameStatusBefore = $game->status->value;
        $gameUpdatedAtBefore = $game->updated_at?->toIso8601String();

        try {
            DB::transaction(function () use ($game): void {
                Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
                $this->app->make(GameStartReadinessChecker::class)->assertReadyForStart($game->id);
            });
        } catch (GameNotReadyForStart) {
            // expected
        }

        $orderAfter = $order->refresh();
        $gameAfter = $game->refresh();

        $this->assertSame($orderStatusBefore, $orderAfter->status->value);
        $this->assertSame($orderUpdatedAtBefore, $orderAfter->updated_at?->toIso8601String());
        $this->assertSame($gameStatusBefore, $gameAfter->status->value);
        $this->assertSame($gameUpdatedAtBefore, $gameAfter->updated_at?->toIso8601String());
    }

    public function test_container_resolves_commerce_implementation(): void
    {
        $instance = $this->app->make(GameStartReadinessChecker::class);
        $this->assertInstanceOf(CommerceGameStartReadinessChecker::class, $instance);
    }
}
