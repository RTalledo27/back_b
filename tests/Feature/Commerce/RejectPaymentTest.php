<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\PaymentRejected;
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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RejectPaymentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY_A = 'reject-key-aaaaaaaaaaaaaaaa';

    /**
     * @return array{User, User, Order, Payment, list<GameNumber>}
     */
    private function setupUnderReviewOrder(int $numberCount = 2): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $game = Game::create([
            'slug' => 'rj-'.fake()->unique()->lexify('?????'),
            'name' => 'R',
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

    public function test_non_admin_returns_403(): void
    {
        [$buyer, , , $payment] = $this->setupUnderReviewOrder();
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", [
            'reason' => 'evidence is illegible',
        ], ['Idempotency-Key' => self::KEY_A])->assertStatus(403);
    }

    public function test_reason_is_required(): void
    {
        [, $admin, , $payment] = $this->setupUnderReviewOrder();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", [], [
            'Idempotency-Key' => self::KEY_A,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_reject_releases_reserved_numbers_and_deletes_reservations(): void
    {
        Event::fake([PaymentRejected::class]);
        [, $admin, $order, $payment, $gns] = $this->setupUnderReviewOrder(2);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", [
            'reason' => 'amount does not match invoice',
        ], ['Idempotency-Key' => self::KEY_A]);

        $response->assertOk();
        $response->assertJsonPath('data.payment.status', 'rejected');
        $response->assertJsonPath('data.payment.rejection_reason', 'amount does not match invoice');
        $response->assertJsonPath('data.order.status', 'rejected');

        $payment->refresh();
        $order->refresh();
        $this->assertSame(PaymentStatus::Rejected, $payment->status);
        $this->assertSame(OrderStatus::Rejected, $order->status);
        $this->assertSame($admin->id, $payment->reviewed_by);
        $this->assertSame('amount does not match invoice', $payment->rejection_reason);

        foreach ($gns as $gn) {
            $this->assertSame(GameNumberStatus::Available, $gn->refresh()->status);
        }

        $this->assertSame(0, NumberReservation::query()->where('order_id', $order->id)->count());

        $this->assertTrue(
            GameEvent::query()->where('game_id', $order->game_id)
                ->where('type', GameEventType::PaymentRejected)->exists()
        );

        Event::assertDispatched(PaymentRejected::class);
    }

    public function test_replay_same_key_returns_same_result_without_duplicate_audit(): void
    {
        [, $admin, $order, $payment] = $this->setupUnderReviewOrder(2);
        Sanctum::actingAs($admin);

        $body = ['reason' => 'evidence missing fields'];

        $first = $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", $body, [
            'Idempotency-Key' => self::KEY_A,
        ])->assertOk();

        $second = $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", $body, [
            'Idempotency-Key' => self::KEY_A,
        ])->assertOk();

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(
            1,
            GameEvent::query()->where('game_id', $order->game_id)
                ->where('type', GameEventType::PaymentRejected)->count(),
        );
    }

    public function test_reject_on_already_approved_payment_returns_422(): void
    {
        [, $admin, , $payment] = $this->setupUnderReviewOrder();
        Sanctum::actingAs($admin);

        $payment->status = PaymentStatus::Approved;
        $payment->saveQuietly();

        $this->postJson("/api/v1/admin/payments/{$payment->id}/reject", [
            'reason' => 'too late',
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_payment_transition');
    }
}
