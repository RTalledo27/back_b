<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PaymentDocument;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

final class PaymentDocumentImmutabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createDocument(array $overrides = []): PaymentDocument
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'doc-test-'.fake()->unique()->lexify('?????'),
            'name' => 'D',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'subtotal_cents' => 100,
            'total_cents' => 100,
            'currency' => 'PEN',
            'status' => OrderStatus::Pending,
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 100,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);

        return PaymentDocument::create(array_replace([
            'payment_id' => $payment->id,
            'disk' => 'payment_evidences',
            'path' => 'p/'.fake()->unique()->uuid().'.pdf',
            'original_filename' => 'receipt.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', (string) fake()->unique()->uuid()),
            'uploaded_by' => $user->id,
        ], $overrides));
    }

    public function test_create_is_allowed(): void
    {
        $doc = $this->createDocument();

        $this->assertNotNull($doc->id);
    }

    public function test_update_via_eloquent_throws(): void
    {
        $doc = $this->createDocument();

        $this->expectException(ImmutableModelException::class);

        $doc->update(['original_filename' => 'tampered.pdf']);
    }

    public function test_save_after_dirty_assignment_throws(): void
    {
        $doc = $this->createDocument();
        $doc->mime_type = 'image/png';

        $this->expectException(ImmutableModelException::class);

        $doc->save();
    }

    public function test_delete_via_eloquent_throws(): void
    {
        $doc = $this->createDocument();

        $this->expectException(ImmutableModelException::class);

        $doc->delete();
    }

    public function test_unique_payment_sha256_blocks_duplicate(): void
    {
        $hash = hash('sha256', 'same-file-bytes');
        $doc = $this->createDocument(['sha256' => $hash]);

        $this->expectException(QueryException::class);

        PaymentDocument::create([
            'payment_id' => $doc->payment_id,
            'disk' => 'payment_evidences',
            'path' => 'p/another.pdf',
            'original_filename' => 'r2.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => $hash,
            'uploaded_by' => $doc->uploaded_by,
        ]);
    }

    public function test_unique_disk_path_blocks_duplicate(): void
    {
        $path = 'p/shared-path.pdf';
        $doc = $this->createDocument(['path' => $path]);

        $this->expectException(QueryException::class);

        PaymentDocument::create([
            'payment_id' => $doc->payment_id,
            'disk' => 'payment_evidences',
            'path' => $path,
            'original_filename' => 'r3.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', 'different'),
            'uploaded_by' => $doc->uploaded_by,
        ]);
    }
}
