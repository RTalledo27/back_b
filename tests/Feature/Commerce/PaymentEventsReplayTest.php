<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\PaymentApproved;
use App\Modules\Commerce\Domain\Events\PaymentRejected;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Events\GameNumbersSold;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Once-only event dispatch contract for approve/reject:
 *  - Same Idempotency-Key replay -> no second dispatch.
 *  - New key over an already-finalised payment -> no dispatch.
 *  - Listener exceptions are reported, never replace the committed result.
 */
final class PaymentEventsReplayTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY_A = 'replay-key-aaaaaaaaaaaaaaaa';

    private const KEY_B = 'replay-key-bbbbbbbbbbbbbbbb';

    /**
     * @return array{User, User, Order, Payment, GameNumber}
     */
    private function setupUnderReview(): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $game = Game::create([
            'slug' => 're-'.fake()->unique()->lexify('?????'),
            'name' => 'R', 'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
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

    public function test_approve_replay_with_same_key_dispatches_event_exactly_once(): void
    {
        Event::fake([PaymentApproved::class, GameNumbersSold::class]);
        [, $admin, , $payment] = $this->setupUnderReview();
        Sanctum::actingAs($admin);
        $url = "/api/v1/admin/payments/{$payment->id}/approve";

        $this->postJson($url, [], ['Idempotency-Key' => self::KEY_A])->assertOk();
        $this->postJson($url, [], ['Idempotency-Key' => self::KEY_A])->assertOk();

        Event::assertDispatched(PaymentApproved::class, 1);
        Event::assertDispatched(GameNumbersSold::class, 1);
    }

    public function test_approve_with_new_key_after_already_approved_does_not_redispatch(): void
    {
        Event::fake([PaymentApproved::class, GameNumbersSold::class]);
        [, $admin, , $payment] = $this->setupUnderReview();
        Sanctum::actingAs($admin);
        $url = "/api/v1/admin/payments/{$payment->id}/approve";

        $this->postJson($url, [], ['Idempotency-Key' => self::KEY_A])->assertOk();
        $this->postJson($url, [], ['Idempotency-Key' => self::KEY_B])->assertOk();

        Event::assertDispatched(PaymentApproved::class, 1);
        Event::assertDispatched(GameNumbersSold::class, 1);
    }

    public function test_reject_replay_with_same_key_dispatches_event_exactly_once(): void
    {
        Event::fake([PaymentRejected::class]);
        [, $admin, , $payment] = $this->setupUnderReview();
        Sanctum::actingAs($admin);
        $url = "/api/v1/admin/payments/{$payment->id}/reject";
        $body = ['reason' => 'evidence does not match invoice'];

        $this->postJson($url, $body, ['Idempotency-Key' => self::KEY_A])->assertOk();
        $this->postJson($url, $body, ['Idempotency-Key' => self::KEY_A])->assertOk();

        Event::assertDispatched(PaymentRejected::class, 1);
    }

    public function test_reject_with_new_key_after_already_rejected_does_not_redispatch(): void
    {
        Event::fake([PaymentRejected::class]);
        [, $admin, , $payment] = $this->setupUnderReview();
        Sanctum::actingAs($admin);
        $url = "/api/v1/admin/payments/{$payment->id}/reject";

        $this->postJson($url, ['reason' => 'first reason'], [
            'Idempotency-Key' => self::KEY_A,
        ])->assertOk();
        $this->postJson($url, ['reason' => 'second reason'], [
            'Idempotency-Key' => self::KEY_B,
        ])->assertOk();

        Event::assertDispatched(PaymentRejected::class, 1);
    }

    public function test_listener_failure_after_commit_preserves_business_state_and_completes_key(): void
    {
        Exceptions::fake();
        Event::listen(PaymentApproved::class, function (): void {
            throw new RuntimeException('listener exploded after commit');
        });

        [$buyer, $admin, $order, $payment] = $this->setupUnderReview();
        Sanctum::actingAs($admin);

        $response = $this->postJson(
            "/api/v1/admin/payments/{$payment->id}/approve",
            [],
            ['Idempotency-Key' => self::KEY_A],
        );

        $response->assertOk();
        $response->assertJsonPath('data.payment.status', 'approved');

        Exceptions::assertReported(RuntimeException::class);

        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(1, GameEntry::query()->where('game_id', $order->game_id)->count());
        $this->assertSame(1, PurchaseAllocation::query()->where('payment_id', $payment->id)->count());

        $row = DB::table('idempotency_keys')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->completed_at, 'Idempotency key must remain completed despite listener failure.');
    }

    public function test_reject_reconstruction_works_when_audit_row_is_missing(): void
    {
        [, $admin, $order, $payment] = $this->setupUnderReview();
        Sanctum::actingAs($admin);
        $url = "/api/v1/admin/payments/{$payment->id}/reject";

        $first = $this->postJson($url, ['reason' => 'illegible'], [
            'Idempotency-Key' => self::KEY_A,
        ])->assertOk();
        $releasedFirst = $first->json('data.released.numbers');

        // Wipe the audit row to prove the reconstruction reads operational
        // tables exclusively (OrderItems → GameNumbers).
        DB::table('game_events')
            ->where('game_id', $order->game_id)
            ->where('type', GameEventType::PaymentRejected->value)
            ->delete();

        $second = $this->postJson($url, ['reason' => 'illegible'], [
            'Idempotency-Key' => self::KEY_B,
        ])->assertOk();

        $releasedSecond = $second->json('data.released.numbers');

        $this->assertNotEmpty($releasedSecond);
        $this->assertSame($releasedFirst, $releasedSecond);
        $this->assertSame(
            $first->json('data.released.game_number_ids'),
            $second->json('data.released.game_number_ids'),
        );
    }
}
