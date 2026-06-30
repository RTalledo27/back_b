<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Actions\RefundOrderAction;
use App\Modules\Commerce\Application\DTOs\RefundOrderData;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Integration tests for the outbox OrderRefunded event (Phase 8.3).
 */
final class OutboxOrderRefundedIntegrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY_A = 'refund-outbox-key-aaaaaaaaaaaa';

    private const KEY_B = 'refund-outbox-key-bbbbbbbbbbbb';

    /**
     * @return array{Order, string, int} [order, gameId, actorUserId]
     */
    private function setupPaidOrder(): array
    {
        $buyer = User::factory()->create();
        $actor = User::factory()->admin()->create();

        $game = Game::create([
            'slug' => 'orf-'.fake()->unique()->lexify('?????'),
            'name' => 'OutboxRefundTest',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 5,
            'ticket_price_cents' => 1000, 'prize_cents' => 5000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => GameStatus::SalesOpen,
        ]);

        $gn = GameNumber::create([
            'game_id' => $game->id, 'number' => 1,
            'status' => GameNumberStatus::Sold,
        ]);

        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::Paid,
            'subtotal_cents' => 1000, 'total_cents' => 1000,
            'currency' => 'PEN', 'expires_at' => null,
            'paid_at' => now()->subMinutes(5),
        ]);

        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 1000,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Approved,
            'submitted_at' => now()->subMinutes(10),
            'reviewed_by' => $actor->id,
            'reviewed_at' => now()->subMinutes(5),
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id,
            'game_number_id' => $gn->id,
            'unit_price_cents' => 1000,
        ]);

        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'user_id' => $buyer->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now()->subMinutes(5),
        ]);

        PurchaseAllocation::create([
            'order_item_id' => $item->id,
            'game_entry_id' => $entry->id,
            'payment_id' => $payment->id,
        ]);

        return [$order, (string) $game->id, $actor->id];
    }

    private function action(): RefundOrderAction
    {
        return $this->app->make(RefundOrderAction::class);
    }

    private function makeData(string $orderId, int $actorUserId, string $key = self::KEY_A): RefundOrderData
    {
        return new RefundOrderData(
            orderId: $orderId,
            actorUserId: $actorUserId,
            reason: 'Motivo de prueba de outbox',
            idempotencyKeyHash: hash('sha256', $key),
            requestFingerprint: hash('sha256', 'fp-'.$key),
        );
    }

    public function test_refund_inserts_outbox_event_inside_transaction(): void
    {
        [$order, $gameId, $actorId] = $this->setupPaidOrder();

        DB::transaction(fn () => $this->action()->executeWithinTransaction($this->makeData($order->id, $actorId), $gameId));

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'order_refunded',
            'aggregate_type' => 'order',
            'aggregate_id' => $order->id,
            'deduplication_key' => 'order_refunded:'.$order->id,
        ]);
    }

    public function test_outbox_payload_contains_required_fields(): void
    {
        [$order, $gameId, $actorId] = $this->setupPaidOrder();

        DB::transaction(fn () => $this->action()->executeWithinTransaction($this->makeData($order->id, $actorId), $gameId));

        $row = DB::table('outbox_events')->where('event_type', 'order_refunded')->first();
        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);

        $this->assertSame(1, $payload['schema_version']);
        $this->assertArrayHasKey('refund_id', $payload);
        $this->assertSame($order->id, $payload['order_id']);
        $this->assertArrayHasKey('payment_id', $payload);
        $this->assertSame($gameId, $payload['game_id']);
        $this->assertArrayHasKey('buyer_user_id', $payload);
        $this->assertArrayHasKey('occurred_at', $payload);
    }

    public function test_outbox_payload_does_not_contain_sensitive_fields(): void
    {
        [$order, $gameId, $actorId] = $this->setupPaidOrder();

        DB::transaction(fn () => $this->action()->executeWithinTransaction($this->makeData($order->id, $actorId), $gameId));

        $row = DB::table('outbox_events')->where('event_type', 'order_refunded')->first();
        $this->assertNotNull($row);
        $payload = json_decode($row->payload, true);

        $forbidden = [
            'idempotency_key_hash', 'request_fingerprint',
            'path', 'disk', 'sha256',
            'reason', 'email', 'name', 'phone',
        ];

        foreach ($forbidden as $field) {
            $this->assertArrayNotHasKey($field, $payload, "Outbox payload must not contain '{$field}'.");
        }
    }

    public function test_same_idempotency_replay_does_not_duplicate_outbox(): void
    {
        [$order, $gameId, $actorId] = $this->setupPaidOrder();
        $data = $this->makeData($order->id, $actorId, self::KEY_A);

        // First call: new refund, inserts outbox row
        $r1 = DB::transaction(fn () => $this->action()->executeWithinTransaction($data, $gameId));
        $this->assertFalse($r1->wasAlreadyRefunded);
        $this->assertDatabaseCount('outbox_events', 1);

        // Second call: same idempotency key = replay, returns existing, no outbox insert
        $r2 = DB::transaction(fn () => $this->action()->executeWithinTransaction($data, $gameId));
        $this->assertTrue($r2->wasAlreadyRefunded);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_different_idempotency_key_after_existing_refund_does_not_duplicate_outbox(): void
    {
        [$order, $gameId, $actorId] = $this->setupPaidOrder();

        // First call with KEY_A
        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            $this->makeData($order->id, $actorId, self::KEY_A), $gameId
        ));
        $this->assertDatabaseCount('outbox_events', 1);

        // Second call with KEY_B (different caller, same order) returns existing — no outbox
        DB::transaction(fn () => $this->action()->executeWithinTransaction(
            $this->makeData($order->id, $actorId, self::KEY_B), $gameId
        ));
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_rollback_removes_outbox_row(): void
    {
        [$order, $gameId, $actorId] = $this->setupPaidOrder();

        try {
            DB::transaction(function () use ($order, $gameId, $actorId): void {
                $this->action()->executeWithinTransaction($this->makeData($order->id, $actorId), $gameId);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('outbox_events', 0);
    }
}
