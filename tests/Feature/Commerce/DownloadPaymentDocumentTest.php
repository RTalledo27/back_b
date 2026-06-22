<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PaymentDocument;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DownloadPaymentDocumentTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{User, User, Payment, PaymentDocument}
     */
    private function setupSubmittedPaymentWithDocument(): array
    {
        Storage::fake('payment_evidences');
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $game = Game::create([
            'slug' => 'dl-'.fake()->unique()->lexify('?????'),
            'name' => 'DL',
            'number_min' => 1, 'number_max' => 5, 'hits_required' => 5,
            'ticket_price_cents' => 500, 'prize_cents' => 2000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true, 'status' => GameStatus::SalesOpen,
        ]);
        $order = Order::create([
            'user_id' => $buyer->id, 'game_id' => $game->id,
            'status' => OrderStatus::PaymentSubmitted,
            'subtotal_cents' => 500, 'total_cents' => 500,
            'currency' => 'PEN', 'expires_at' => null,
        ]);
        $payment = Payment::create([
            'order_id' => $order->id, 'amount_cents' => 500,
            'currency' => 'PEN', 'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::UnderReview, 'submitted_at' => now(),
        ]);

        $docId = (string) Str::uuid7();
        $path = $payment->id.'/'.$docId.'.pdf';
        Storage::disk('payment_evidences')->put($path, "%PDF-1.4\nfake-bytes");

        $document = PaymentDocument::create([
            'id' => $docId,
            'payment_id' => $payment->id,
            'disk' => 'payment_evidences',
            'path' => $path,
            'original_filename' => 'receipt.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 16,
            'sha256' => str_repeat('a', 64),
            'uploaded_by' => $buyer->id,
        ]);

        return [$buyer, $admin, $payment, $document];
    }

    public function test_admin_can_download_document_with_private_headers(): void
    {
        [, $admin, $payment, $document] = $this->setupSubmittedPaymentWithDocument();
        Sanctum::actingAs($admin);

        $response = $this->get("/api/v1/admin/payments/{$payment->id}/documents/{$document->id}/download");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        // Symfony normalises Cache-Control directives alphabetically.
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_player_cannot_download_document(): void
    {
        [$buyer, , $payment, $document] = $this->setupSubmittedPaymentWithDocument();
        Sanctum::actingAs($buyer);

        $this->get("/api/v1/admin/payments/{$payment->id}/documents/{$document->id}/download")
            ->assertStatus(403);
    }

    public function test_cross_payment_id_returns_404_without_leaking_existence(): void
    {
        [, $admin] = $this->setupSubmittedPaymentWithDocument();
        // Build a second payment with its own document.
        [, , $otherPayment, $otherDoc] = $this->setupSubmittedPaymentWithDocument();
        Sanctum::actingAs($admin);

        // Try to download otherDoc through a different payment's path.
        [, , $firstPayment] = $this->setupSubmittedPaymentWithDocument();

        $this->get("/api/v1/admin/payments/{$firstPayment->id}/documents/{$otherDoc->id}/download")
            ->assertNotFound();
    }

    public function test_missing_file_on_disk_returns_404(): void
    {
        [, $admin, $payment, $document] = $this->setupSubmittedPaymentWithDocument();
        Storage::disk('payment_evidences')->delete($document->path);

        Sanctum::actingAs($admin);
        $this->get("/api/v1/admin/payments/{$payment->id}/documents/{$document->id}/download")
            ->assertNotFound();
    }
}
