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
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the canonical lock order for the two payment actions.
 *
 *   - Approve (Phase 3.3):
 *       games -> orders -> payments -> order_items -> number_reservations -> game_numbers
 *
 *   - Reject (Phase 2, unchanged):
 *       orders -> payments -> order_items -> number_reservations -> game_numbers
 *
 * Reject only releases ownership and intentionally does not take the
 * engine-wide Game root lock — adding it would needlessly increase
 * contention while a running game is being resolved.
 *
 * Uses DB::listen to capture every SELECT ... FOR UPDATE emitted by the
 * Action and asserts the table-acquisition sequence.
 */
final class PaymentActionsLockOrderTest extends TestCase
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
            'slug' => 'lo-'.fake()->unique()->lexify('?????'),
            'name' => 'L',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
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

    /**
     * @return list<string> Ordered list of table names that received FOR UPDATE.
     */
    private function captureLockSequence(callable $body): array
    {
        /** @var list<string> $tables */
        $tables = [];

        DB::listen(function ($query) use (&$tables): void {
            $sql = mb_strtolower((string) $query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            foreach (['games', 'orders', 'payments', 'order_items', 'number_reservations', 'game_numbers'] as $table) {
                if (preg_match('/\bfrom\s+"?'.preg_quote($table, '/').'"?/i', $sql) === 1) {
                    $tables[] = $table;

                    return;
                }
            }
        });

        $body();

        return $tables;
    }

    public function test_approve_locks_starting_with_game_then_canonical_chain(): void
    {
        [, $admin, , $payment] = $this->setupUnderReview();

        $sequence = $this->captureLockSequence(function () use ($admin, $payment): void {
            $this->app->make(ApprovePaymentAction::class)->execute(
                new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $admin->id),
            );
        });

        $this->assertSame(
            ['games', 'orders', 'payments', 'order_items', 'number_reservations', 'game_numbers'],
            $sequence,
            'Approve must lock games first (engine root), then the canonical Commerce chain.',
        );
    }

    public function test_reject_locks_in_order_payment_items_reservations_numbers(): void
    {
        [, $admin, , $payment] = $this->setupUnderReview();

        $sequence = $this->captureLockSequence(function () use ($admin, $payment): void {
            $this->app->make(RejectPaymentAction::class)->execute(
                new RejectPaymentData(
                    paymentId: $payment->id,
                    reviewerUserId: $admin->id,
                    reason: 'evidence mismatch',
                ),
            );
        });

        $this->assertSame(
            ['orders', 'payments', 'order_items', 'number_reservations', 'game_numbers'],
            $sequence,
            'Reject must lock tables in the canonical order.',
        );
    }
}
