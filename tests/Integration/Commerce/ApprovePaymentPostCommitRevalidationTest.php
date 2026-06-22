<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

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
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Integration\Support\RawPdoConnection;
use Tests\TestCase;

/**
 * Phase 3.3 "post-commit revalidation" guard — NOT a real concurrency
 * race. An outside session takes a row lock, mutates game.status, and
 * COMMITs before ApprovePaymentAction starts. The Action then re-reads
 * the row under its own lock and must reject with
 * GameNotAcceptingPayments. The point is to assert the revalidation
 * branch behaves correctly when state has changed under our feet — not
 * that the Action waited on a lock.
 *
 * The true `Approve ↔ Start` race lives in
 * tests/Integration/Game/ApproveAndStartProcessConcurrencyTest.php
 * (Phase 3.4) and uses two real PHP processes.
 */
final class ApprovePaymentPostCommitRevalidationTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * @return array{User, User, Game, Order, Payment, GameNumber}
     */
    private function setupScenario(): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $game = Game::create([
            'slug' => 'race-'.fake()->unique()->lexify('?????'),
            'name' => 'RC', 'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesClosed,
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

    public function test_game_lock_serialises_approve_and_status_change_to_running_rejects(): void
    {
        [, $admin, $game, $order, $payment, $gn] = $this->setupScenario();
        $pdo = null;

        try {
            $pdo = RawPdoConnection::open();
            $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
            // Make the Laravel connection give up quickly if the outside
            // session keeps the lock for too long.
            DB::statement("SET LOCAL lock_timeout = '4000ms'");
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM games WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $game->id]);
            $lock->fetch();

            // Outside session updates status to running while still holding
            // the lock — the approve attempt below will block until COMMIT.
            $update = $pdo->prepare("UPDATE games SET status = 'running', started_at = now() WHERE id = :id");
            $update->execute(['id' => $game->id]);

            // Kick off approve in a fiber-less way: it will block on the
            // games row; we then COMMIT to release the lock and observe
            // the failure.
            $approveTriggered = false;
            $threwExpected = false;

            // Release the lock first (in a real concurrent run, approve
            // would already be waiting). For a deterministic test we
            // commit before invoking approve so it reads the new state.
            $pdo->commit();
            $pdo = null;

            try {
                $this->app->make(ApprovePaymentAction::class)->execute(
                    new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $admin->id),
                );
                $approveTriggered = true;
            } catch (GameNotAcceptingPayments $e) {
                $threwExpected = true;
                $this->assertSame($game->id, $e->gameId);
                $this->assertSame('running', $e->currentStatus);
            }

            $this->assertFalse($approveTriggered);
            $this->assertTrue($threwExpected);

            // Full rollback: nothing changed on the Commerce / engine sides.
            $this->assertSame(PaymentStatus::UnderReview, $payment->refresh()->status);
            $this->assertSame(OrderStatus::PaymentSubmitted, $order->refresh()->status);
            $this->assertSame(GameNumberStatus::Reserved, $gn->refresh()->status);
            $this->assertSame(0, GameEntry::query()->where('game_id', $game->id)->count());
            $this->assertSame(0, PurchaseAllocation::query()->where('payment_id', $payment->id)->count());
        } finally {
            RawPdoConnection::teardown($pdo);
        }
    }

    public function test_approve_proceeds_when_outside_session_releases_lock_keeping_sales_closed(): void
    {
        [, $admin, $game, , $payment] = $this->setupScenario();
        $pdo = null;

        try {
            $pdo = RawPdoConnection::open();
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM games WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $game->id]);
            $lock->fetch();
            // Release without altering status.
            $pdo->commit();
            $pdo = null;

            $result = $this->app->make(ApprovePaymentAction::class)->execute(
                new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $admin->id),
            );

            $this->assertTrue($result->wasTransitionApplied);
            $this->assertSame('paid', $result->orderStatus);
        } finally {
            RawPdoConnection::teardown($pdo);
        }
    }
}
