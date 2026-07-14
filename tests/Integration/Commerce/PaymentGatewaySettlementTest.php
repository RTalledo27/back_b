<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Gateway\Actions\SettleGatewayPaidTransactionAction;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentSettlementRequest;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentSettlementResponse;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayAttemptData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayTransactionData;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayAttemptAction;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayTransactionAction;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayTransaction;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PaymentGatewaySettlementTest extends TestCase
{
    use DatabaseTruncation;

    public function test_paid_transaction_applies_the_commercial_transition_and_outbox(): void
    {
        Notification::fake();
        [, $order, $payment, $transaction] = $this->makeFixture();

        $response = $this->settle($transaction);

        $this->assertTrue($response->wasSettlementApplied);
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNotNull($transaction->refresh()->applied_at);
        $this->assertSame(1, GameEntry::query()->where('game_id', $order->game_id)->count());
        $this->assertSame(1, PurchaseAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, GameEvent::query()->where('type', GameEventType::PaymentApproved)->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'payment_approved')->count());
        Notification::assertNothingSent();
    }

    public function test_paid_transaction_replay_is_idempotent_without_duplicate_business_effects(): void
    {
        [, $order, $payment, $transaction] = $this->makeFixture();

        $first = $this->settle($transaction);
        $paidAt = $order->refresh()->paid_at?->toIso8601String();
        $reviewedAt = $payment->refresh()->reviewed_at?->toIso8601String();
        $second = $this->settle($transaction->refresh());

        $this->assertFalse($second->wasSettlementApplied);
        $this->assertSame($first->gameEntryIds, $second->gameEntryIds);
        $this->assertSame($paidAt, $order->refresh()->paid_at?->toIso8601String());
        $this->assertSame($reviewedAt, $payment->refresh()->reviewed_at?->toIso8601String());
        $this->assertSame(1, GameEntry::query()->where('game_id', $order->game_id)->count());
        $this->assertSame(1, PurchaseAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, GameEvent::query()->where('type', GameEventType::PaymentApproved)->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'payment_approved')->count());
    }

    public function test_captured_transaction_uses_the_same_paid_path(): void
    {
        [, $order, $payment, $transaction] = $this->makeFixture(status: 'captured');

        $this->settle($transaction);

        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
    }

    #[DataProvider('nonPayableStatuses')]
    public function test_non_paid_transaction_does_not_settle(string $status): void
    {
        [, $order, $payment, $transaction] = $this->makeFixture(status: $status);

        try {
            $this->settle($transaction);
            $this->fail('A non-paid gateway transaction must not settle.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(1009, $exception->getCode());
        }

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(0, GameEntry::query()->count());
        $this->assertSame(0, DB::table('outbox_events')->count());
    }

    public static function nonPayableStatuses(): array
    {
        return [
            'authorized' => ['authorized'],
            'failed' => ['failed'],
            'expired' => ['expired'],
        ];
    }

    public function test_amount_currency_and_provider_mismatches_are_conflicts(): void
    {
        [, $order, $payment, $transaction] = $this->makeFixture();
        $transaction->amount_cents = 501;
        $transaction->save();
        $this->assertSettlementConflict($transaction);

        [, $order, $payment, $transaction] = $this->makeFixture();
        $transaction->currency = 'USD';
        $transaction->save();
        $this->assertSettlementConflict($transaction);

        [, $order, $payment, $transaction] = $this->makeFixture();
        $this->assertSettlementConflict($transaction, provider: 'other-provider');

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    public function test_a_second_paid_transaction_for_the_same_payment_is_a_conflict(): void
    {
        [, , $payment, $firstTransaction] = $this->makeFixture();
        $this->settle($firstTransaction);

        $secondTransaction = $this->makeTransaction(
            paymentId: $payment->id,
            providerTransactionId: 'fake-secondary-transaction',
        );

        $this->assertSettlementConflict($secondTransaction);
        $this->assertSame(1, GameEntry::query()->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'payment_approved')->count());
    }

    public function test_two_processes_settle_one_transaction_without_duplicate_effects(): void
    {
        [, $order, $payment, $transaction] = $this->makeFixture();
        $processes = [
            $this->spawnSettlement($transaction->id),
            $this->spawnSettlement($transaction->id),
        ];

        foreach ($processes as $process) {
            $process->wait();
        }

        $outputs = array_map(function (Process $process): array {
            $output = json_decode(trim($process->getOutput()), true) ?? [];
            $output['_stdout'] = $process->getOutput();
            $output['_stderr'] = $process->getErrorOutput();

            return $output;
        }, $processes);

        foreach ($outputs as $output) {
            $this->assertTrue($output['ok'] ?? false, json_encode($output));
        }

        $appliedFlags = array_map(
            fn (array $output): bool => (bool) ($output['was_settlement_applied'] ?? false),
            $outputs,
        );
        sort($appliedFlags);

        $this->assertSame([false, true], $appliedFlags);
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(1, GameEntry::query()->where('game_id', $order->game_id)->count());
        $this->assertSame(1, PurchaseAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'payment_approved')->count());
    }

    private function settle(
        PaymentGatewayTransaction $transaction,
        string $provider = 'fake',
    ): GatewayPaymentSettlementResponse {
        return app(SettleGatewayPaidTransactionAction::class)->execute(
            new GatewayPaymentSettlementRequest(
                transactionId: $transaction->id,
                provider: $provider,
            ),
        );
    }

    private function assertSettlementConflict(
        PaymentGatewayTransaction $transaction,
        string $provider = 'fake',
    ): void {
        try {
            $this->settle($transaction, $provider);
            $this->fail('The gateway settlement should have been rejected as a conflict.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(1010, $exception->getCode());
        }
    }

    private function spawnSettlement(string $transactionId): Process
    {
        $config = config('database.connections.pgsql');
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/run-engine-action.php'),
            'settle',
            'TRANSACTION_ID='.$transactionId,
            'PROVIDER=fake',
        ]);
        $process->setWorkingDirectory(base_path());
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
     * @return array{User, Order, Payment, PaymentGatewayTransaction}
     */
    private function makeFixture(string $status = 'paid'): array
    {
        $buyer = User::factory()->create();
        $game = Game::create([
            'slug' => 'settlement-'.fake()->unique()->lexify('?????'),
            'name' => 'Gateway settlement game',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => GameStatus::SalesOpen,
        ]);
        $gameNumber = GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => GameNumberStatus::Reserved,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id,
            'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500,
            'total_cents' => 500,
            'currency' => 'PEN',
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'game_number_id' => $gameNumber->id,
            'unit_price_cents' => 500,
        ]);
        NumberReservation::create([
            'order_id' => $order->id,
            'game_number_id' => $gameNumber->id,
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);
        $attempt = (new RecordPaymentGatewayAttemptAction)->execute(new PaymentGatewayAttemptData(
            orderId: $order->id,
            paymentId: $payment->id,
            amountCents: 500,
            currency: 'PEN',
            provider: 'fake',
            environment: 'sandbox',
            idempotencyKeyHash: hash('sha256', 'attempt-'.$payment->id),
            requestFingerprint: hash('sha256', 'fingerprint-'.$payment->id),
            providerAttemptId: 'fake-attempt-'.$payment->id,
        ));
        $transaction = (new RecordPaymentGatewayTransactionAction)->execute(new PaymentGatewayTransactionData(
            paymentGatewayAttemptId: $attempt->id,
            paymentId: $payment->id,
            provider: 'fake',
            providerTransactionId: 'fake-transaction-'.$payment->id,
            status: $status,
            amountCents: 500,
            currency: 'PEN',
            capturedAt: in_array($status, ['paid', 'captured'], true) ? CarbonImmutable::now() : null,
        ));

        $this->assertSame($gameNumber->id, $item->game_number_id);

        return [$buyer, $order, $payment, $transaction];
    }

    private function makeTransaction(string $paymentId, string $providerTransactionId): PaymentGatewayTransaction
    {
        $payment = Payment::query()->findOrFail($paymentId);
        $attempt = (new RecordPaymentGatewayAttemptAction)->execute(new PaymentGatewayAttemptData(
            orderId: $payment->order_id,
            paymentId: $payment->id,
            amountCents: 500,
            currency: 'PEN',
            provider: 'fake',
            environment: 'sandbox',
            idempotencyKeyHash: hash('sha256', 'secondary-attempt-'.$providerTransactionId),
            requestFingerprint: hash('sha256', 'secondary-fingerprint-'.$providerTransactionId),
            providerAttemptId: 'fake-attempt-'.$providerTransactionId,
        ));

        return (new RecordPaymentGatewayTransactionAction)->execute(new PaymentGatewayTransactionData(
            paymentGatewayAttemptId: $attempt->id,
            paymentId: $payment->id,
            provider: 'fake',
            providerTransactionId: $providerTransactionId,
            status: 'paid',
            amountCents: 500,
            currency: 'PEN',
            capturedAt: CarbonImmutable::now(),
        ));
    }
}
