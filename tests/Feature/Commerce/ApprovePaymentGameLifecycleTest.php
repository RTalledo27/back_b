<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Actions\ApprovePaymentAction;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentData;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Exceptions\GameNotAcceptingPayments;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 3.3 gate: ApprovePaymentAction must enforce the engine-wide rule
 * "no new sales after the game has moved past sales_closed", and must
 * still allow idempotent replays for already-approved payments regardless
 * of the current game status.
 */
final class ApprovePaymentGameLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{User, User, Game, Order, Payment, GameNumber}
     */
    private function setupUnderReviewOrder(GameStatus $gameStatus): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $game = Game::create([
            'slug' => 'lc-'.fake()->unique()->lexify('?????'),
            'name' => 'L', 'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => $gameStatus,
        ]);
        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => 1, 'status' => GameNumberStatus::Reserved,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::PaymentSubmitted,
            'subtotal_cents' => 500, 'total_cents' => 500,
            'currency' => 'PEN', 'expires_at' => null,
        ]);
        OrderItem::create(['order_id' => $order->id, 'game_number_id' => $gn->id, 'unit_price_cents' => 500]);
        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $gn->id]);
        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::UnderReview, 'submitted_at' => now()->subMinute(),
        ]);

        return [$buyer, $admin, $game, $order, $payment, $gn];
    }

    public function test_new_approval_allowed_in_sales_open(): void
    {
        [, $admin, , , $payment] = $this->setupUnderReviewOrder(GameStatus::SalesOpen);

        $result = $this->app->make(ApprovePaymentAction::class)->execute(
            new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $admin->id),
        );

        $this->assertTrue($result->wasTransitionApplied);
        $this->assertSame(PaymentStatus::Approved->value, $result->paymentStatus);
    }

    public function test_new_approval_allowed_in_sales_closed(): void
    {
        [, $admin, , , $payment] = $this->setupUnderReviewOrder(GameStatus::SalesClosed);

        $result = $this->app->make(ApprovePaymentAction::class)->execute(
            new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $admin->id),
        );

        $this->assertTrue($result->wasTransitionApplied);
    }

    /**
     * @return iterable<string, array{GameStatus}>
     */
    public static function gameStatusesThatRejectNewApproval(): iterable
    {
        return [
            'running' => [GameStatus::Running],
            'paused' => [GameStatus::Paused],
            'resolving' => [GameStatus::Resolving],
            'completed' => [GameStatus::Completed],
            'cancelled' => [GameStatus::Cancelled],
        ];
    }

    #[DataProvider('gameStatusesThatRejectNewApproval')]
    public function test_new_approval_rejected_when_game_no_longer_accepts_payments(GameStatus $status): void
    {
        [, $admin, $game, $order, $payment, $gn] = $this->setupUnderReviewOrder($status);

        try {
            $this->app->make(ApprovePaymentAction::class)->execute(
                new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $admin->id),
            );
            $this->fail('Expected GameNotAcceptingPayments.');
        } catch (GameNotAcceptingPayments $e) {
            $this->assertSame($game->id, $e->gameId);
            $this->assertSame($status->value, $e->currentStatus);
            $this->assertSame(['sales_open', 'sales_closed'], $e->allowedStatuses);
        }

        // Full rollback expected — nothing mutated.
        $this->assertSame(PaymentStatus::UnderReview, $payment->refresh()->status);
        $this->assertSame(OrderStatus::PaymentSubmitted, $order->refresh()->status);
        $this->assertSame(GameNumberStatus::Reserved, $gn->refresh()->status);
        $this->assertSame(0, GameEntry::query()->where('game_id', $game->id)->count());
        $this->assertSame(0, PurchaseAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(
            0,
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::PaymentApproved)->count(),
        );
    }

    public function test_already_approved_payment_can_be_reconstructed_even_when_game_is_running(): void
    {
        [, $admin, $game, , $payment] = $this->setupUnderReviewOrder(GameStatus::SalesClosed);

        // First approval succeeds in SalesClosed.
        $original = $this->app->make(ApprovePaymentAction::class)->execute(
            new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $admin->id),
        );
        $this->assertTrue($original->wasTransitionApplied);

        // Game then moves to Running.
        $game->status = GameStatus::Running;
        $game->saveQuietly();

        $approvedCountBefore = GameEvent::query()->where('game_id', $game->id)
            ->where('type', GameEventType::PaymentApproved)->count();
        $entriesBefore = GameEntry::query()->where('game_id', $game->id)->count();
        $allocationsBefore = PurchaseAllocation::query()->where('payment_id', $payment->id)->count();

        // Replay must reconstruct without throwing GameNotAcceptingPayments.
        $replay = $this->app->make(ApprovePaymentAction::class)->execute(
            new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $admin->id),
        );
        $this->assertFalse($replay->wasTransitionApplied);
        $this->assertSame($original->paymentId, $replay->paymentId);
        $this->assertSame($original->gameEntryIds, $replay->gameEntryIds);
        $this->assertSame($original->numbers, $replay->numbers);

        // No duplication.
        $this->assertSame(
            $approvedCountBefore,
            GameEvent::query()->where('game_id', $game->id)
                ->where('type', GameEventType::PaymentApproved)->count(),
        );
        $this->assertSame($entriesBefore, GameEntry::query()->where('game_id', $game->id)->count());
        $this->assertSame(
            $allocationsBefore,
            PurchaseAllocation::query()->where('payment_id', $payment->id)->count(),
        );
    }
}
