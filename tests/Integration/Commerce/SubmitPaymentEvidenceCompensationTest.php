<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\DTOs\SubmitPaymentEvidenceData;
use App\Modules\Commerce\Application\Support\SubmitPaymentEvidenceOrchestrator;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\PaymentEvidenceSubmitted;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PaymentDocument;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Exercises the compensation paths around storage + transaction.
 *
 *  - Real PostgreSQL failure during the transaction -> file deleted,
 *    key released, original error propagated.
 *  - Duplicate file detection -> just-stored file deleted (cleanup
 *    only, not compensation: the COMMIT was successful).
 *  - Replay -> file is still analysed (the SHA-256 feeds the
 *    idempotency hash) but no new file is written and no transaction
 *    is opened.
 *  - Dispatch failure AFTER commit -> the business result is durable
 *    and must NOT be compensated.
 */
final class SubmitPaymentEvidenceCompensationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{User, Order, Payment, GameNumber}
     */
    private function setupPendingOrder(): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'ev-comp-'.fake()->unique()->lexify('????'),
            'name' => 'EC',
            'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
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
            'user_id' => $user->id, 'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 500, 'total_cents' => 500,
            'currency' => 'PEN', 'expires_at' => now()->addMinutes(10),
        ]);
        NumberReservation::create(['order_id' => $order->id, 'game_number_id' => $gn->id]);
        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500, 'currency' => 'PEN',
            'method' => PaymentMethod::Manual, 'status' => PaymentStatus::Pending,
        ]);

        return [$user, $order, $payment, $gn];
    }

    private function pdfUpload(string $content = "%PDF-1.4\nx"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('r.pdf', $content);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('payment_evidences');
    }

    public function test_db_failure_during_transaction_deletes_file_and_releases_key_and_propagates_original_error(): void
    {
        [$user, $order, $payment] = $this->setupPendingOrder();

        try {
            // Inject a PostgreSQL trigger that aborts every INSERT to
            // payment_documents. Lives only in the test DB (LazilyRefreshDatabase
            // already rolled back to a savepoint at test start).
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION test_evidence_explode() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'simulated test failure on payment_documents insert';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER test_evidence_explode_trigger
                BEFORE INSERT ON payment_documents
                FOR EACH ROW EXECUTE FUNCTION test_evidence_explode();
            SQL);

            $orchestrator = $this->app->make(SubmitPaymentEvidenceOrchestrator::class);

            $caught = null;
            try {
                $orchestrator->handle(
                    data: new SubmitPaymentEvidenceData(orderId: $order->id, userId: $user->id),
                    uploadedFile: $this->pdfUpload(),
                    idempotencyKey: 'trigger-key-aaaaaaaaaaaaaaaa',
                    requestMethod: 'POST',
                    requestPath: "api/v1/me/orders/{$order->id}/payment-evidence",
                );
            } catch (Throwable $e) {
                $caught = $e;
            }

            $this->assertNotNull($caught, 'Orchestrator must propagate the original DB error.');
            $this->assertInstanceOf(QueryException::class, $caught);
            $this->assertStringContainsString(
                'simulated test failure',
                $caught->getMessage(),
                'The ORIGINAL PostgreSQL error must be preserved by compensation.',
            );

            // File deleted
            $files = Storage::disk('payment_evidences')->allFiles($payment->id);
            $this->assertSame([], $files, 'Compensation must remove the stored evidence file.');

            // No document row created
            $this->assertSame(0, PaymentDocument::query()->count());

            // Idempotency key released (no incomplete row left behind)
            $this->assertSame(0, DB::table('idempotency_keys')->count());

            // Order/payment state untouched
            $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
            $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        } finally {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS test_evidence_explode_trigger ON payment_documents;
                DROP FUNCTION IF EXISTS test_evidence_explode();
            SQL);
        }
    }

    public function test_dispatch_failure_after_commit_does_not_revert_business(): void
    {
        [$user, $order, $payment] = $this->setupPendingOrder();

        // Register a real listener that throws — this exercises the
        // post-commit error path inside the orchestrator. Exceptions::fake()
        // captures the reported error so we can assert it was reported and
        // NOT rethrown.
        Exceptions::fake();
        Event::listen(PaymentEvidenceSubmitted::class, function (): void {
            throw new RuntimeException('listener exploded after commit');
        });

        $orchestrator = $this->app->make(SubmitPaymentEvidenceOrchestrator::class);

        $result = $orchestrator->handle(
            data: new SubmitPaymentEvidenceData(orderId: $order->id, userId: $user->id),
            uploadedFile: $this->pdfUpload(),
            idempotencyKey: 'post-commit-key-aaaaaaaaaaaa',
            requestMethod: 'POST',
            requestPath: "api/v1/me/orders/{$order->id}/payment-evidence",
        );

        // Listener error was reported, not rethrown.
        Exceptions::assertReported(RuntimeException::class);

        // Business result is durable.
        $document = PaymentDocument::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame($result->documentId, $document->id);
        Storage::disk('payment_evidences')->assertExists($document->path);

        $this->assertSame(OrderStatus::PaymentSubmitted, $order->refresh()->status);
        $this->assertSame(PaymentStatus::UnderReview, $payment->refresh()->status);
        $this->assertNull($order->refresh()->expires_at);

        // Key is COMPLETED (not released).
        $row = DB::table('idempotency_keys')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->completed_at, 'Idempotency key must remain completed after post-commit dispatch error.');
    }

    public function test_replay_analyses_file_but_does_not_write_anything_new(): void
    {
        [$user, $order, $payment] = $this->setupPendingOrder();

        $orchestrator = $this->app->make(SubmitPaymentEvidenceOrchestrator::class);

        $first = $orchestrator->handle(
            data: new SubmitPaymentEvidenceData(orderId: $order->id, userId: $user->id),
            uploadedFile: $this->pdfUpload(),
            idempotencyKey: 'replay-key-aaaaaaaaaaaaaaaaaaaa',
            requestMethod: 'POST',
            requestPath: "api/v1/me/orders/{$order->id}/payment-evidence",
        );

        $filesAfterFirst = Storage::disk('payment_evidences')->allFiles($payment->id);
        $this->assertCount(1, $filesAfterFirst);

        $second = $orchestrator->handle(
            data: new SubmitPaymentEvidenceData(orderId: $order->id, userId: $user->id),
            uploadedFile: $this->pdfUpload(),
            idempotencyKey: 'replay-key-aaaaaaaaaaaaaaaaaaaa',
            requestMethod: 'POST',
            requestPath: "api/v1/me/orders/{$order->id}/payment-evidence",
        );

        $this->assertEquals($first, $second, 'Replay must return the cached result.');

        $filesAfterReplay = Storage::disk('payment_evidences')->allFiles($payment->id);
        $this->assertSame($filesAfterFirst, $filesAfterReplay, 'Replay must not produce any new file.');
        $this->assertSame(1, PaymentDocument::query()->where('payment_id', $payment->id)->count());
    }

    public function test_duplicate_detection_deletes_duplicate_file_and_keeps_original_document(): void
    {
        [$user, $order, $payment] = $this->setupPendingOrder();

        $orchestrator = $this->app->make(SubmitPaymentEvidenceOrchestrator::class);

        $orchestrator->handle(
            data: new SubmitPaymentEvidenceData(orderId: $order->id, userId: $user->id),
            uploadedFile: $this->pdfUpload(),
            idempotencyKey: 'dup-key-aaaaaaaaaaaaaaaaaaaa',
            requestMethod: 'POST',
            requestPath: "api/v1/me/orders/{$order->id}/payment-evidence",
        );

        $originalDoc = PaymentDocument::query()->where('payment_id', $payment->id)->firstOrFail();
        $originalPath = $originalDoc->path;

        $result = $orchestrator->handle(
            data: new SubmitPaymentEvidenceData(orderId: $order->id, userId: $user->id),
            uploadedFile: $this->pdfUpload(),
            idempotencyKey: 'dup-key-bbbbbbbbbbbbbbbbbbbb',
            requestMethod: 'POST',
            requestPath: "api/v1/me/orders/{$order->id}/payment-evidence",
        );

        $this->assertSame($originalDoc->id, $result->documentId);
        $this->assertSame(1, PaymentDocument::query()->where('payment_id', $payment->id)->count());

        $filesOnDisk = Storage::disk('payment_evidences')->allFiles($payment->id);
        $this->assertCount(1, $filesOnDisk);
        $this->assertSame($originalPath, $filesOnDisk[0]);
    }
}
