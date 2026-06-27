<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\Commerce\Domain\Models\Refund;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real concurrency tests for RefundOrderAction using two PHP processes.
 * Each process opens its own PostgreSQL connection and competes for the
 * canonical lock order: Game → Order → Refund → Payment → Entries → Numbers.
 *
 * Four scenarios:
 *  (a) Same order + same key  → 1 refund, both succeed
 *  (b) Same order + diff keys → 1 refund, second returns existing
 *  (c) Same key + diff fingerprint → idempotency_conflict, no second refund
 *  (d) Refund vs StartGameAction → Game FOR UPDATE serializes, no deadlock
 */
final class RefundOrderConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE refunds, game_events, game_entries, game_numbers, draw_commands, game_winners, game_draws, game_number_counters, purchase_allocations, payment_documents, payments, number_reservations, order_items, orders, idempotency_keys, games, users RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    /**
     * Creates a fully paid order: Order=Paid, Payment=Approved,
     * GameNumbers=Sold, GameEntries=Confirmed, PurchaseAllocations set.
     *
     * @return array{User, User, Game, Order, Payment}
     */
    private function setupPaidOrder(GameStatus $gameStatus = GameStatus::SalesOpen): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $game = Game::create([
            'slug' => 'rc-'.fake()->unique()->lexify('?????'),
            'name' => 'Rifa Concurrencia',
            'number_min' => 1, 'number_max' => 30, 'hits_required' => 10,
            'ticket_price_cents' => 1000, 'prize_cents' => 5000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => $gameStatus,
        ]);

        $gn = GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => GameNumberStatus::Sold,
        ]);

        $order = Order::create([
            'user_id' => $buyer->id,
            'game_id' => $game->id,
            'status' => OrderStatus::Paid,
            'subtotal_cents' => 1000,
            'total_cents' => 1000,
            'currency' => 'PEN',
            'expires_at' => null,
            'paid_at' => now()->subMinutes(5),
        ]);

        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 1000,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Approved,
            'submitted_at' => now()->subMinutes(10),
            'reviewed_at' => now()->subMinutes(5),
            'reviewed_by' => $admin->id,
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

        return [$buyer, $admin, $game, $order, $payment];
    }

    /**
     * Spawns a refund process and returns it running.
     *
     * @param  array<string, string|int>  $overrides
     */
    private function spawnRefund(
        string $orderId,
        int $actorUserId,
        string $idempotencyKey,
        string $reason = 'Motivo de prueba de concurrencia de reembolso.',
    ): Process {
        return $this->spawn([
            'refund',
            'ORDER_ID='.$orderId,
            'ACTOR_USER_ID='.$actorUserId,
            'IDEMPOTENCY_KEY='.$idempotencyKey,
            'REASON='.$reason,
        ]);
    }

    private function spawnStart(string $gameId, int $actorUserId): Process
    {
        return $this->spawn([
            'start',
            'GAME_ID='.$gameId,
            'ACTOR_USER_ID='.$actorUserId,
        ]);
    }

    private function spawn(array $args): Process
    {
        $base = base_path();
        $config = config('database.connections.pgsql');

        $process = new Process(array_merge(
            [
                PHP_BINARY,
                $base.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Support'.DIRECTORY_SEPARATOR.'run-engine-action.php',
            ],
            $args,
        ));
        $process->setWorkingDirectory($base);
        $process->setEnv([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $config['host'],
            'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => $config['database'],
            'DB_USERNAME' => $config['username'],
            'DB_PASSWORD' => $config['password'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
        ]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /**
     * (a) Same order, same idempotency key, simultaneous race.
     *
     * Expected outcome: exactly 1 refund row. Both processes exit ok=true.
     * The one that won the lock gets wasAlreadyRefunded=false, the other gets true.
     */
    public function test_same_order_same_key_race_produces_exactly_one_refund(): void
    {
        [, $admin, , $order] = $this->setupPaidOrder();

        $key = 'concurrency-key-same-aaa';

        $p1 = null;
        $p2 = null;
        try {
            $p1 = $this->spawnRefund((string) $order->id, (int) $admin->id, $key);
            $p2 = $this->spawnRefund((string) $order->id, (int) $admin->id, $key);

            $p1->wait();
            $p2->wait();

            $out1 = json_decode(trim($p1->getOutput()), true) ?? [];
            $out2 = json_decode(trim($p2->getOutput()), true) ?? [];

            $this->assertIsArray($out1, 'Process 1 output: '.$p1->getOutput().$p1->getErrorOutput());
            $this->assertIsArray($out2, 'Process 2 output: '.$p2->getOutput().$p2->getErrorOutput());

            // Both must succeed.
            $this->assertTrue($out1['ok'] ?? false, 'Process 1 failed: '.json_encode($out1));
            $this->assertTrue($out2['ok'] ?? false, 'Process 2 failed: '.json_encode($out2));

            // Exactly 1 refund row in DB.
            $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());

            // Exactly one process created the refund; the other found it.
            $flags = [(bool) ($out1['was_already_refunded'] ?? true), (bool) ($out2['was_already_refunded'] ?? true)];
            sort($flags);
            $this->assertSame([false, true], $flags, 'One process must be the creator and the other the replayer.');

            // Exactly 1 OrderRefunded audit, no duplicates.
            $this->assertSame(1, GameEvent::query()->where('type', GameEventType::OrderRefunded)->count());
        } finally {
            foreach ([$p1, $p2] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }

    /**
     * (b) Same order, different idempotency keys, simultaneous race.
     *
     * Expected outcome: exactly 1 refund row. One process succeeds as creator
     * (wasAlreadyRefunded=false); the other finds the existing refund and
     * returns it (wasAlreadyRefunded=true, different key hash → order already refunded).
     */
    public function test_same_order_different_keys_race_produces_exactly_one_refund(): void
    {
        [, $admin, , $order] = $this->setupPaidOrder();

        $keyA = 'concurrency-key-diff-aaa';
        $keyB = 'concurrency-key-diff-bbb';

        $p1 = null;
        $p2 = null;
        try {
            $p1 = $this->spawnRefund((string) $order->id, (int) $admin->id, $keyA);
            $p2 = $this->spawnRefund((string) $order->id, (int) $admin->id, $keyB);

            $p1->wait();
            $p2->wait();

            $out1 = json_decode(trim($p1->getOutput()), true) ?? [];
            $out2 = json_decode(trim($p2->getOutput()), true) ?? [];

            $this->assertIsArray($out1, 'Process 1: '.$p1->getOutput().$p1->getErrorOutput());
            $this->assertIsArray($out2, 'Process 2: '.$p2->getOutput().$p2->getErrorOutput());

            // Both must succeed.
            $this->assertTrue($out1['ok'] ?? false, 'Process 1 failed: '.json_encode($out1));
            $this->assertTrue($out2['ok'] ?? false, 'Process 2 failed: '.json_encode($out2));

            // Exactly 1 refund row.
            $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());

            // Exactly 1 created, 1 returned existing.
            $flags = [(bool) ($out1['was_already_refunded'] ?? true), (bool) ($out2['was_already_refunded'] ?? true)];
            sort($flags);
            $this->assertSame([false, true], $flags);

            // No duplicate audit.
            $this->assertSame(1, GameEvent::query()->where('type', GameEventType::OrderRefunded)->count());
        } finally {
            foreach ([$p1, $p2] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }

    /**
     * (c) Same idempotency key, different request fingerprint (different reason).
     *
     * Sequence (not concurrent): first call succeeds and stores the refund.
     * Second call, same key but different reason → different fingerprint →
     * IdempotencyKeyMismatch. No second refund is created.
     */
    public function test_same_key_different_fingerprint_raises_idempotency_conflict(): void
    {
        [, $admin, , $order] = $this->setupPaidOrder();

        $key = 'concurrency-key-fp-conflict';
        $reasonA = 'Motivo A para prueba de conflicto de fingerprint.';
        $reasonB = 'Motivo B diferente que cambia el fingerprint del request.';

        $p1 = null;
        $p2 = null;
        try {
            // First call — must succeed and create the refund.
            $p1 = $this->spawnRefund((string) $order->id, (int) $admin->id, $key, $reasonA);
            $p1->wait();

            $out1 = json_decode(trim($p1->getOutput()), true) ?? [];
            $this->assertTrue($out1['ok'] ?? false, 'First refund failed: '.json_encode($out1));
            $this->assertFalse((bool) ($out1['was_already_refunded'] ?? true));

            $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());

            // Second call — same key, different reason → fingerprint mismatch.
            $p2 = $this->spawnRefund((string) $order->id, (int) $admin->id, $key, $reasonB);
            $p2->wait();

            $out2 = json_decode(trim($p2->getOutput()), true) ?? [];
            $this->assertFalse($out2['ok'] ?? true, 'Expected IdempotencyKeyMismatch but process succeeded.');
            $this->assertStringContainsString(
                'IdempotencyKeyMismatch',
                $out2['class'] ?? '',
                'Wrong exception: '.json_encode($out2),
            );

            // Still exactly 1 refund — no second insert.
            $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());

            // Still exactly 1 audit event.
            $this->assertSame(1, GameEvent::query()->where('type', GameEventType::OrderRefunded)->count());
        } finally {
            foreach ([$p1, $p2] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }

    /**
     * (d) Refund vs StartGameAction race.
     *
     * Both acquire Game FOR UPDATE as their first lock, so they serialize
     * without deadlock or timeout. Two admissible outcomes:
     *
     *  A) Refund wins: game stays SalesClosed, 1 refund, start may then
     *     succeed (extra Confirmed entry passes readiness) or fail if the
     *     game has no remaining Confirmed entries.
     *
     *  B) Start wins: game becomes Running, 0 refunds created, refund fails
     *     with OrderNotRefundable (Running ∉ ALLOWED_GAME_STATUSES).
     *
     * Invariant: ≤1 refund, ≤1 GameStarted event, no deadlock.
     */
    public function test_refund_vs_start_game_race_serializes_without_deadlock(): void
    {
        [, $admin, $game, $order] = $this->setupPaidOrder(GameStatus::SalesClosed);

        // Extra Confirmed entry (independent of the order being refunded).
        // Ensures StartGameAction's readiness check passes even if the
        // order's entry becomes Refunded when Refund wins the race.
        $extraGn = GameNumber::create([
            'game_id' => $game->id,
            'number' => 20,
            'status' => GameNumberStatus::Sold,
        ]);
        $extraBuyer = User::factory()->create();
        GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $extraGn->id,
            'user_id' => $extraBuyer->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now()->subMinutes(10),
        ]);

        // Also set scheduled_start_at in the past so Start's time check passes.
        $game->update(['scheduled_start_at' => now()->subMinutes(2)]);

        $pRefund = null;
        $pStart = null;
        try {
            $pRefund = $this->spawnRefund(
                (string) $order->id,
                (int) $admin->id,
                'concurrency-key-refund-vs-start',
            );
            $pStart = $this->spawnStart((string) $game->id, (int) $admin->id);

            $pRefund->wait();
            $pStart->wait();

            $outRefund = json_decode(trim($pRefund->getOutput()), true) ?? [];
            $outStart = json_decode(trim($pStart->getOutput()), true) ?? [];

            $this->assertIsArray($outRefund, 'Refund process: '.$pRefund->getOutput().$pRefund->getErrorOutput());
            $this->assertIsArray($outStart, 'Start process: '.$pStart->getOutput().$pStart->getErrorOutput());

            // No deadlock: at least one process must have succeeded.
            $atLeastOneSucceeded = ($outRefund['ok'] ?? false) || ($outStart['ok'] ?? false);
            $this->assertTrue($atLeastOneSucceeded, 'Both processes failed — possible deadlock or misconfiguration.');

            // ≤1 refund row.
            $this->assertLessThanOrEqual(1, Refund::query()->where('order_id', $order->id)->count());

            // ≤1 GameStarted event.
            $this->assertLessThanOrEqual(
                1,
                GameEvent::query()->where('game_id', $game->id)->where('type', GameEventType::GameStarted)->count(),
            );

            if ($outRefund['ok'] ?? false) {
                // Branch A: refund won the lock.
                $this->assertSame(1, Refund::query()->where('order_id', $order->id)->count());
            } else {
                // Branch B: start won the lock first; refund failed with OrderNotRefundable.
                $this->assertStringContainsString(
                    'OrderNotRefundable',
                    $outRefund['class'] ?? '',
                    'Refund failed with unexpected exception: '.json_encode($outRefund),
                );
                $this->assertSame(0, Refund::query()->where('order_id', $order->id)->count());
            }

            if ($outStart['ok'] ?? false) {
                $this->assertSame(
                    1,
                    GameEvent::query()
                        ->where('game_id', $game->id)
                        ->where('type', GameEventType::GameStarted)
                        ->count(),
                );
            }
        } finally {
            foreach ([$pRefund, $pStart] as $proc) {
                if ($proc instanceof Process && $proc->isRunning()) {
                    $proc->stop(2);
                }
            }
        }
    }
}
