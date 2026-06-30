<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Actions\ApprovePaymentAction;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentData;
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
 * Integration tests for the outbox PaymentApproved event:
 *  - A new approval inserts exactly one outbox row inside the transaction.
 *  - The payload contains only the allowed fields (no sensitive data).
 *  - Idempotent replay (wasTransitionApplied = false) does not add a duplicate.
 *  - ON CONFLICT DO NOTHING does not abort the domain transaction.
 */
final class OutboxPaymentApprovedIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{Payment, Order, int} [payment, order, reviewerUserId]
     */
    private function setupUnderReviewPayment(): array
    {
        $buyer = User::factory()->create();
        $reviewer = User::factory()->admin()->create();

        $game = Game::create([
            'slug' => 'ob-'.fake()->unique()->lexify('?????'),
            'name' => 'ObTest',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::SalesOpen,
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
            'order_id' => $order->id,
            'game_number_id' => $gn->id,
            'unit_price_cents' => 500,
        ]);

        NumberReservation::create([
            'order_id' => $order->id,
            'game_number_id' => $gn->id,
        ]);

        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::UnderReview,
            'submitted_at' => now()->subMinute(),
        ]);

        return [$payment, $order, $reviewer->id];
    }

    private function action(): ApprovePaymentAction
    {
        return $this->app->make(ApprovePaymentAction::class);
    }

    public function test_approve_inserts_outbox_event_inside_transaction(): void
    {
        [$payment, , $reviewerId] = $this->setupUnderReviewPayment();

        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $reviewerId)
        ));

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'payment_approved',
            'aggregate_type' => 'payment',
            'aggregate_id' => $payment->id,
            'deduplication_key' => 'payment_approved:'.$payment->id,
        ]);
    }

    public function test_outbox_payload_contains_required_fields(): void
    {
        [$payment, $order, $reviewerId] = $this->setupUnderReviewPayment();

        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $reviewerId)
        ));

        $row = DB::table('outbox_events')
            ->where('event_type', 'payment_approved')
            ->first();

        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);

        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame($payment->id, $payload['payment_id']);
        $this->assertSame($order->id, $payload['order_id']);
        $this->assertSame($order->game_id, $payload['game_id']);
        $this->assertArrayHasKey('buyer_user_id', $payload);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    public function test_outbox_payload_does_not_contain_sensitive_fields(): void
    {
        [$payment, , $reviewerId] = $this->setupUnderReviewPayment();

        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $reviewerId)
        ));

        $row = DB::table('outbox_events')
            ->where('event_type', 'payment_approved')
            ->first();

        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);

        $forbidden = [
            'email', 'name', 'phone', 'evidence_path', 'disk',
            'sha256', 'idempotency_key', 'request_fingerprint',
            'token', 'bank_account', 'reviewer_user_id',
        ];

        foreach ($forbidden as $field) {
            $this->assertArrayNotHasKey(
                $field,
                $payload,
                "Outbox payload must not contain '{$field}'."
            );
        }
    }

    public function test_idempotent_replay_does_not_insert_duplicate_outbox_row(): void
    {
        [$payment, , $reviewerId] = $this->setupUnderReviewPayment();
        $data = new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $reviewerId);

        // First call: new transition, inserts outbox row
        $r1 = DB::transaction(fn () => $this->action()->executeWithinTransaction($data));
        $this->assertTrue($r1->wasTransitionApplied);

        $this->assertDatabaseCount('outbox_events', 1);

        // Second call: idempotent replay (payment is already Approved),
        // takes the buildResultFromOperationalState branch — no outbox insert.
        $r2 = DB::transaction(fn () => $this->action()->executeWithinTransaction($data));
        $this->assertFalse($r2->wasTransitionApplied);

        // Still exactly one row — no duplicate inserted.
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_outbox_event_is_initially_pending(): void
    {
        [$payment, , $reviewerId] = $this->setupUnderReviewPayment();

        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $reviewerId)
        ));

        $row = DB::table('outbox_events')
            ->where('event_type', 'payment_approved')
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->processed_at);
        $this->assertNull($row->failed_at);
        $this->assertSame(0, (int) $row->attempts);
        $this->assertNull($row->locked_at);
        $this->assertNull($row->locked_by);
    }

    public function test_rollback_removes_outbox_row(): void
    {
        [$payment, , $reviewerId] = $this->setupUnderReviewPayment();

        try {
            DB::transaction(function () use ($payment, $reviewerId): void {
                $this->action()->executeWithinTransaction(
                    new ApprovePaymentData(paymentId: $payment->id, reviewerUserId: $reviewerId)
                );
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('outbox_events', 0);
        $payment->refresh();
        $this->assertSame(PaymentStatus::UnderReview, $payment->status);
    }
}
