<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Modules\Commerce\Application\Gateway\Actions\ProcessGatewayWebhookAction;
use App\Modules\Commerce\Application\Gateway\Actions\RecordGatewayWebhookNotificationAction;
use App\Modules\Commerce\Application\Gateway\Actions\SettleGatewayPaidTransactionAction;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentSettlementRequest;
use App\Modules\Commerce\Application\Gateway\GatewayWebhookRecordRequest;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayAttemptData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayTransactionData;
use App\Modules\Commerce\Application\Gateway\ProcessGatewayWebhookData;
use App\Modules\Commerce\Application\Gateway\ProcessGatewayWebhookResult;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayAttemptAction;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayTransactionAction;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\Gateway\FakePaymentGatewayWebhookVerifier;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayTransaction;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayWebhook;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PaymentGatewayWebhookProcessingTest extends TestCase
{
    use DatabaseTruncation;

    #[Test]
    public function a_paid_webhook_records_the_transaction_and_applies_existing_settlement(): void
    {
        Notification::fake();
        [, $order, $payment, $attempt, $webhook] = $this->makeFixture();

        $result = $this->process($webhook);

        $this->assertSame('paid', $result->status->value);
        $this->assertTrue($result->wasSettlementApplied);
        $this->assertNotNull($result->processedAt);
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(1, PaymentGatewayTransaction::query()->count());
        $this->assertNotNull(PaymentGatewayTransaction::query()->firstOrFail()->applied_at);
        $this->assertSame(1, GameEntry::query()->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'payment_approved')->count());
        $this->assertSame(1, DB::table('game_events')->where('type', GameEventType::PaymentApproved->value)->count());
        $this->assertSame($attempt->provider_attempt_id, $webhook->refresh()->provider_attempt_id);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_captured_webhook_uses_the_same_paid_settlement_path(): void
    {
        [, $order, $payment, , $webhook] = $this->makeFixture(status: 'captured');

        $this->process($webhook);

        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertNotNull(PaymentGatewayTransaction::query()->firstOrFail()->captured_at);
    }

    #[Test]
    public function a_processed_webhook_replays_without_duplicate_commercial_effects(): void
    {
        [, $order, $payment, , $webhook] = $this->makeFixture();

        $first = $this->process($webhook);
        $second = $this->process($webhook->refresh());

        $this->assertFalse($first->wasAlreadyProcessed);
        $this->assertTrue($second->wasAlreadyProcessed);
        $this->assertSame($first->transactionId, $second->transactionId);
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(1, PaymentGatewayTransaction::query()->count());
        $this->assertSame(1, GameEntry::query()->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'payment_approved')->count());
    }

    #[Test]
    public function a_webhook_retries_after_settlement_was_applied_before_processing_was_marked(): void
    {
        [, $order, $payment, $attempt, $webhook] = $this->makeFixture();
        $transaction = (new RecordPaymentGatewayTransactionAction)->execute(new PaymentGatewayTransactionData(
            paymentGatewayAttemptId: $attempt->id,
            paymentId: $payment->id,
            provider: 'fake',
            providerTransactionId: $webhook->provider_transaction_id,
            status: 'paid',
            amountCents: 500,
            currency: 'PEN',
            capturedAt: $webhook->occurred_at->toImmutable(),
        ));
        app(SettleGatewayPaidTransactionAction::class)->execute(
            new GatewayPaymentSettlementRequest(
                transactionId: $transaction->id,
                provider: 'fake',
            ),
        );

        $result = $this->process($webhook->refresh());

        $this->assertFalse($result->wasAlreadyProcessed);
        $this->assertFalse($result->wasSettlementApplied);
        $this->assertNotNull($webhook->refresh()->processed_at);
        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(1, PaymentGatewayTransaction::query()->count());
        $this->assertSame(1, GameEntry::query()->count());
    }

    #[DataProvider('technicalStatuses')]
    public function test_non_paid_webhooks_only_record_technical_state(string $status): void
    {
        [, $order, $payment, , $webhook] = $this->makeFixture(status: $status);

        $result = $this->process($webhook);

        $this->assertSame($status, $result->status->value);
        $this->assertFalse($result->wasSettlementApplied);
        $this->assertNotNull($webhook->refresh()->processed_at);
        $this->assertNull($webhook->failed_at);
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(1, PaymentGatewayTransaction::query()->count());
        $this->assertSame(0, GameEntry::query()->count());
        $this->assertSame(0, DB::table('outbox_events')->count());
    }

    public static function technicalStatuses(): array
    {
        return [
            'authorized' => ['authorized'],
            'failed' => ['failed'],
            'expired' => ['expired'],
        ];
    }

    #[Test]
    public function a_signature_or_metadata_failure_is_durable_and_does_not_touch_commerce(): void
    {
        [, $order, $payment, $attempt, $webhook] = $this->makeFixture();
        $webhook->signature_verified = false;
        $webhook->save();

        $this->assertProcessingFailure($webhook);

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(0, PaymentGatewayTransaction::query()->count());
        $this->assertSame(1, $webhook->refresh()->processing_attempts);
        $this->assertNotNull($webhook->failed_at);
        $this->assertSame('pending', $attempt->refresh()->status);
    }

    #[Test]
    public function amount_currency_provider_and_environment_mismatches_are_controlled_failures(): void
    {
        foreach ([
            ['amount_cents' => 501],
            ['currency' => 'USD'],
            ['provider_attempt_id' => 'unknown-attempt'],
            ['environment' => 'production'],
        ] as $overrides) {
            [, $order, $payment, , $webhook] = $this->makeFixture(payloadOverrides: $overrides);

            $this->assertProcessingFailure($webhook);
            $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
            $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
            $this->assertSame(0, PaymentGatewayTransaction::query()->count());
        }
    }

    #[Test]
    public function an_unknown_stored_status_is_a_controlled_durable_failure(): void
    {
        [, $order, $payment, , $webhook] = $this->makeFixture();
        $webhook->normalized_status = 'unknown';
        $webhook->save();

        $this->assertProcessingFailure($webhook);

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertNotNull($webhook->refresh()->failed_at);
        $this->assertStringContainsString('not supported', (string) $webhook->last_error);
    }

    #[Test]
    public function two_real_processes_process_one_paid_webhook_without_duplicate_effects(): void
    {
        [, $order, $payment, , $webhook] = $this->makeFixture();
        $processes = [
            $this->spawnProcessing($webhook->id),
            $this->spawnProcessing($webhook->id),
        ];

        foreach ($processes as $process) {
            $process->wait();
        }

        foreach ($processes as $process) {
            $output = json_decode(trim($process->getOutput()), true) ?? [];
            $this->assertTrue($output['ok'] ?? false, $process->getOutput().$process->getErrorOutput());
        }

        $this->assertSame(PaymentStatus::Approved, $payment->refresh()->status);
        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(1, PaymentGatewayTransaction::query()->count());
        $this->assertSame(1, GameEntry::query()->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'payment_approved')->count());
        $this->assertNotNull($webhook->refresh()->processed_at);
    }

    #[Test]
    public function the_same_webhook_event_rejects_immutable_metadata_conflicts(): void
    {
        config(['payment_gateways.credentials.webhook_secret' => 'test-secret']);
        $now = CarbonImmutable::parse('2026-07-14T12:00:00Z');
        $rawPayload = json_encode($this->payload('evt-conflict', $now), JSON_THROW_ON_ERROR);
        $signature = app(FakePaymentGatewayWebhookVerifier::class)
            ->sign($rawPayload, 'test-secret', $now->timestamp);
        $request = new GatewayWebhookRecordRequest('fake', $rawPayload, $signature, now: $now);
        app(RecordGatewayWebhookNotificationAction::class)->execute($request);

        $changed = json_encode($this->payload('evt-conflict', $now, ['provider_transaction_id' => 'different']), JSON_THROW_ON_ERROR);
        $changedSignature = app(FakePaymentGatewayWebhookVerifier::class)
            ->sign($changed, 'test-secret', $now->timestamp);

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(1002);
        app(RecordGatewayWebhookNotificationAction::class)->execute(
            new GatewayWebhookRecordRequest('fake', $changed, $changedSignature, now: $now),
        );
    }

    private function process(PaymentGatewayWebhook $webhook): ProcessGatewayWebhookResult
    {
        return app(ProcessGatewayWebhookAction::class)->execute(
            new ProcessGatewayWebhookData($webhook->id),
        );
    }

    private function assertProcessingFailure(PaymentGatewayWebhook $webhook): void
    {
        try {
            $this->process($webhook);
            $this->fail('The webhook should have failed in a controlled way.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(1011, $exception->getCode());
        }
    }

    private function spawnProcessing(string $webhookId): Process
    {
        $config = config('database.connections.pgsql');
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/run-engine-action.php'),
            'process-webhook',
            'WEBHOOK_ID='.$webhookId,
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
     * @return array{User, Order, Payment, PaymentGatewayAttempt, PaymentGatewayWebhook}
     */
    private function makeFixture(
        string $status = 'paid',
        array $payloadOverrides = [],
    ): array {
        config(['payment_gateways.credentials.webhook_secret' => 'test-secret']);
        $buyer = \App\Models\User::factory()->create();
        $game = Game::create([
            'slug' => 'webhook-'.fake()->unique()->lexify('?????'),
            'name' => 'Gateway webhook game',
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
            idempotencyKeyHash: hash('sha256', 'webhook-attempt-'.$payment->id),
            requestFingerprint: hash('sha256', 'webhook-fingerprint-'.$payment->id),
            providerAttemptId: 'fake-attempt-'.$payment->id,
        ));
        $now = CarbonImmutable::parse('2026-07-14T12:00:00Z');
        $payload = $this->payload($attempt->provider_attempt_id, $now, array_merge([
            'provider_event_id' => 'evt-'.$payment->id,
            'status' => $status,
            'provider_attempt_id' => $attempt->provider_attempt_id,
            'provider_transaction_id' => 'fake-transaction-'.$payment->id,
        ], $payloadOverrides));
        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $verifier = app(FakePaymentGatewayWebhookVerifier::class);
        $webhook = app(RecordGatewayWebhookNotificationAction::class)->execute(new GatewayWebhookRecordRequest(
            provider: 'fake',
            rawPayload: $rawPayload,
            signature: $verifier->sign($rawPayload, 'test-secret', $now->timestamp),
            now: $now,
        ));

        $this->assertSame($gameNumber->id, $item->game_number_id);

        return [$buyer, $order, $payment, $attempt, PaymentGatewayWebhook::query()->findOrFail($webhook->webhookId)];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $eventId, CarbonImmutable $occurredAt, array $overrides = []): array
    {
        return array_replace([
            'provider_event_id' => $eventId,
            'event_type' => 'payment.status_changed',
            'status' => 'paid',
            'amount_cents' => 500,
            'currency' => 'PEN',
            'occurred_at' => $occurredAt->toIso8601String(),
            'provider_attempt_id' => $eventId,
            'provider_transaction_id' => 'transaction-'.$eventId,
            'environment' => 'sandbox',
        ], $overrides);
    }
}
