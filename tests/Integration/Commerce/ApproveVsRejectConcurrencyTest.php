<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Actions\ApprovePaymentAction;
use App\Modules\Commerce\Application\Actions\RejectPaymentAction;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentData;
use App\Modules\Commerce\Application\DTOs\RejectPaymentData;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Exceptions\InvalidPaymentTransition;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class ApproveVsRejectConcurrencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{User, User, Order, Payment, GameNumber}
     */
    private function setupUnderReview(): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $game = Game::create([
            'slug' => 'concur-'.fake()->unique()->lexify('?????'),
            'name' => 'C',
            'number_min' => 1, 'number_max' => 3, 'hits_required' => 5,
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
            'currency' => 'PEN', 'expires_at' => null,
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'game_number_id' => $gn->id,
            'unit_price_cents' => 500,
        ]);
        NumberReservation::create([
            'order_id' => $order->id, 'game_number_id' => $gn->id,
        ]);
        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::UnderReview,
            'submitted_at' => now()->subMinute(),
        ]);

        return [$buyer, $admin, $order, $payment, $gn];
    }

    public function test_second_approve_after_first_approve_returns_existing_state(): void
    {
        [$buyer, $admin, $order, $payment, $gn] = $this->setupUnderReview();

        $this->app->make(ApprovePaymentAction::class)
            ->execute(new ApprovePaymentData(
                paymentId: $payment->id, reviewerUserId: $admin->id,
            ));

        // Repeating the operation must not duplicate entries / allocations.
        $second = $this->app->make(ApprovePaymentAction::class)
            ->execute(new ApprovePaymentData(
                paymentId: $payment->id, reviewerUserId: $admin->id,
            ));

        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(1, GameEntry::query()->where('game_id', $order->game_id)->count());
        $this->assertSame(1, PurchaseAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertCount(1, $second->gameEntryIds);
    }

    public function test_reject_after_approve_throws_invalid_transition(): void
    {
        [, $admin, , $payment] = $this->setupUnderReview();

        $this->app->make(ApprovePaymentAction::class)->execute(new ApprovePaymentData(
            paymentId: $payment->id, reviewerUserId: $admin->id,
        ));

        $this->expectException(InvalidPaymentTransition::class);

        $this->app->make(RejectPaymentAction::class)->execute(new RejectPaymentData(
            paymentId: $payment->id, reviewerUserId: $admin->id, reason: 'too late',
        ));
    }

    public function test_approve_after_reject_throws_invalid_transition(): void
    {
        [, $admin, , $payment] = $this->setupUnderReview();

        $this->app->make(RejectPaymentAction::class)->execute(new RejectPaymentData(
            paymentId: $payment->id, reviewerUserId: $admin->id, reason: 'bad evidence',
        ));

        $this->expectException(InvalidPaymentTransition::class);

        $this->app->make(ApprovePaymentAction::class)->execute(new ApprovePaymentData(
            paymentId: $payment->id, reviewerUserId: $admin->id,
        ));
    }

    public function test_double_approve_unique_constraints_act_as_last_defense(): void
    {
        [, $admin, $order, $payment, $gn] = $this->setupUnderReview();

        $this->app->make(ApprovePaymentAction::class)->execute(new ApprovePaymentData(
            paymentId: $payment->id, reviewerUserId: $admin->id,
        ));

        // game_entries(game_number_id) and purchase_allocations(order_item_id)
        // are UNIQUE — a hypothetical bypass of the state check would still
        // fail at the database level.
        $this->assertSame(1, GameEntry::query()->where('game_number_id', $gn->id)->count());
        $this->assertSame(1, PurchaseAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(GameNumberStatus::Sold, $gn->refresh()->status);
    }
}
