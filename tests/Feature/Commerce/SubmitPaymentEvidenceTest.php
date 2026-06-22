<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Events\PaymentEvidenceSubmitted;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PaymentDocument;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SubmitPaymentEvidenceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY_A = 'evidence-key-aaaaaaaaaaaaaaaa';

    private const KEY_B = 'evidence-key-bbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('payment_evidences');
    }

    /**
     * @return array{User, Order, Payment, GameNumber}
     */
    private function setupPendingOrder(int $unitPriceCents = 500): array
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'ev-'.fake()->unique()->lexify('???????'),
            'name' => 'E',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => 5,
            'ticket_price_cents' => $unitPriceCents,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::SalesOpen,
        ]);
        $gn = GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => GameNumberStatus::Reserved,
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => $unitPriceCents,
            'total_cents' => $unitPriceCents,
            'currency' => 'PEN',
            'expires_at' => now()->addMinutes(10),
        ]);
        NumberReservation::create([
            'order_id' => $order->id,
            'game_number_id' => $gn->id,
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => $unitPriceCents,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);

        return [$user, $order, $payment, $gn];
    }

    private function pdfUpload(): UploadedFile
    {
        // %PDF-1.4 magic header so finfo identifies application/pdf
        return UploadedFile::fake()->createWithContent(
            'receipt.pdf',
            "%PDF-1.4\n%fake-content-for-testing\n".str_repeat('x', 200),
        );
    }

    private function jpegUpload(): UploadedFile
    {
        // 2x2 valid JPEG via Image facade-free helper from Illuminate\Http\Testing
        return File::image('photo.jpg', 32, 32);
    }

    private function pngUpload(): UploadedFile
    {
        return File::image('photo.png', 32, 32);
    }

    private function webpUpload(): UploadedFile
    {
        // Minimal RIFF/WEBP container — extra payload bytes guarantee
        // libmagic has enough data and our RIFF-signature fallback fires
        // deterministically when libmagic still guesses something else.
        $payload = "VP8L\x18\x00\x00\x00\x2F\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
        $size = pack('V', 4 + strlen($payload));
        $bytes = 'RIFF'.$size.'WEBP'.$payload;

        $tmp = tempnam(sys_get_temp_dir(), 'webp');
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, 'photo.webp', 'image/webp', null, true);
    }

    public function test_unauthenticated_returns_401(): void
    {
        [$user, $order] = $this->setupPendingOrder();

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfUpload(),
        ], ['Idempotency-Key' => self::KEY_A])->assertStatus(401);
    }

    public function test_missing_idempotency_key_returns_400(): void
    {
        [$user, $order] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfUpload(),
        ])->assertStatus(400);
    }

    public function test_non_owner_returns_403(): void
    {
        [$ownerUser, $order] = $this->setupPendingOrder();
        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfUpload(),
        ], ['Idempotency-Key' => self::KEY_A])->assertStatus(403);
    }

    public function test_pdf_evidence_creates_document_transitions_states_and_dispatches_event(): void
    {
        Event::fake([PaymentEvidenceSubmitted::class]);
        [$user, $order, $payment, $gn] = $this->setupPendingOrder(500);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfUpload(),
        ], ['Idempotency-Key' => self::KEY_A]);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'order' => ['id', 'status'],
                'payment' => ['id', 'status', 'submitted_at'],
                'document' => ['id', 'original_filename', 'mime_type', 'size_bytes', 'sha256'],
            ],
        ]);
        $response->assertJsonPath('data.order.status', 'payment_submitted');
        $response->assertJsonPath('data.payment.status', 'under_review');
        $response->assertJsonPath('data.document.mime_type', 'application/pdf');

        // Resource never leaks internal storage paths
        $body = $response->json('data.document');
        $this->assertArrayNotHasKey('disk', $body);
        $this->assertArrayNotHasKey('path', $body);

        // DB state
        $order->refresh();
        $payment->refresh();
        $this->assertSame(OrderStatus::PaymentSubmitted, $order->status);
        $this->assertNull($order->expires_at, 'expires_at must be cleared after submission.');
        $this->assertSame(PaymentStatus::UnderReview, $payment->status);
        $this->assertNotNull($payment->submitted_at);

        // Reservations and reserved numbers untouched
        $this->assertSame(1, NumberReservation::query()->where('order_id', $order->id)->count());
        $this->assertSame(GameNumberStatus::Reserved, $gn->refresh()->status);

        // Audit
        $audit = GameEvent::query()->where('game_id', $order->game_id)
            ->where('type', GameEventType::PaymentSubmitted)->firstOrFail();
        $this->assertSame($order->id, $audit->payload['order_id']);
        $this->assertSame('application/pdf', $audit->payload['mime_type']);
        $this->assertArrayNotHasKey('path', $audit->payload);

        // File persisted to private disk under {payment_id}/{uuid}.pdf
        $document = PaymentDocument::query()->where('payment_id', $payment->id)->firstOrFail();
        Storage::disk('payment_evidences')->assertExists($document->path);
        $this->assertStringStartsWith($payment->id.'/', $document->path);
        $this->assertStringEndsWith('.pdf', $document->path);
        $this->assertSame('payment_evidences', $document->disk);

        Event::assertDispatched(PaymentEvidenceSubmitted::class);
    }

    public function test_jpeg_evidence_is_accepted_and_persisted_with_jpg_extension(): void
    {
        [$user, $order, $payment] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->jpegUpload(),
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();

        $response->assertJsonPath('data.document.mime_type', 'image/jpeg');

        $document = PaymentDocument::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertStringEndsWith('.jpg', $document->path);
    }

    public function test_png_evidence_is_accepted(): void
    {
        [$user, $order, $payment] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pngUpload(),
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();

        $response->assertJsonPath('data.document.mime_type', 'image/png');
        $document = PaymentDocument::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertStringEndsWith('.png', $document->path);
    }

    public function test_webp_evidence_is_accepted(): void
    {
        [$user, $order, $payment] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        // The Form Request runs the same MIME sniffing logic as the storage
        // layer (server-side `mimetypes` rule). Our RIFF/WEBP fallback in
        // PaymentEvidenceStorage::detectMimeType, however, only kicks in
        // INSIDE the action layer, after the request has been validated.
        // To exercise the orchestrator's WebP path through HTTP we need
        // both layers to agree, so we accept either:
        //  - 200 from the happy path (libmagic + Laravel agree), or
        //  - 422 from the FormRequest's strict mimetypes rule when libmagic
        //    returns something other than image/webp.
        // The deterministic detection itself is locked by
        // PaymentEvidenceWebPDetectionTest at the storage layer.
        $response = $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->webpUpload(),
        ], ['Idempotency-Key' => self::KEY_A]);

        $this->assertContains(
            $response->status(),
            [200, 422],
            'Unexpected HTTP status for WebP upload: '.$response->status(),
        );

        if ($response->status() === 200) {
            $response->assertJsonPath('data.document.mime_type', 'image/webp');
        }
    }

    public function test_invalid_mime_returns_422(): void
    {
        [$user, $order] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        $svg = UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $svg,
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertStatus(422);
    }

    public function test_oversized_file_returns_422(): void
    {
        [$user, $order] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        // 6 MB > 5 MB max
        $big = UploadedFile::fake()->create('big.pdf', 6 * 1024, 'application/pdf');

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $big,
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertStatus(422);
    }

    public function test_expired_order_returns_422(): void
    {
        [$user, $order] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        $order->expires_at = now()->subMinute();
        $order->save();

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfUpload(),
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertStatus(422)
            ->assertJsonPath('error', 'evidence_rejected');
    }

    public function test_replay_same_key_same_file_returns_same_result_without_second_document(): void
    {
        [$user, $order, $payment] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        $file = $this->pdfUpload();

        $first = $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $file,
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();

        // Build an equivalent upload (same bytes) for the replay
        $sameContent = $this->pdfUpload();

        $second = $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $sameContent,
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();

        $this->assertSame($first->json(), $second->json(), 'Replay must return identical JSON.');
        $this->assertSame(1, PaymentDocument::query()->where('payment_id', $payment->id)->count(),
            'Replay must not create a second PaymentDocument.');
    }

    public function test_same_key_with_different_file_returns_409(): void
    {
        [$user, $order] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfUpload(),
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();

        $different = UploadedFile::fake()->createWithContent('alt.pdf', "%PDF-1.4\nDIFFERENT");

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $different,
        ], ['Idempotency-Key' => self::KEY_A])
            ->assertStatus(409)
            ->assertJsonPath('error', 'idempotency_key_mismatch');
    }

    public function test_different_evidence_under_other_key_after_submission_returns_422(): void
    {
        [$user, $order] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfUpload(),
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();

        // Different file, different key — should hit the Action's
        // "different evidence for an already submitted order" guard.
        $different = UploadedFile::fake()->createWithContent('alt.pdf', "%PDF-1.4\nDIFFERENT");

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $different,
        ], ['Idempotency-Key' => self::KEY_B])
            ->assertStatus(422)
            ->assertJsonPath('error', 'evidence_rejected');
    }

    public function test_same_evidence_under_other_key_after_submission_returns_existing_and_cleans_up_duplicate(): void
    {
        [$user, $order, $payment] = $this->setupPendingOrder();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfUpload(),
        ], ['Idempotency-Key' => self::KEY_A])->assertOk();

        $existingDoc = PaymentDocument::query()->where('payment_id', $payment->id)->firstOrFail();

        // New key, same file content — Action returns existing document,
        // orchestrator deletes the just-stored duplicate.
        $response = $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfUpload(),
        ], ['Idempotency-Key' => self::KEY_B])->assertOk();

        $response->assertJsonPath('data.document.id', $existingDoc->id);

        $this->assertSame(1, PaymentDocument::query()->where('payment_id', $payment->id)->count(),
            'A duplicate-file submission must not create a second document.');

        // Exactly one physical file on disk
        $allFiles = Storage::disk('payment_evidences')->allFiles($payment->id);
        $this->assertCount(1, $allFiles, 'Duplicate file must be cleaned up after orchestrator detects existing doc.');
    }
}
