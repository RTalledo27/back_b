<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class EmailVerificationCommerceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const IDEM_KEY_A = 'verify-guard-key-aaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('payment_evidences');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createGameWithNumbers(int $count = 3): array
    {
        $game = Game::create([
            'slug' => 'vg-'.fake()->unique()->lexify('???????'),
            'name' => 'Rifa',
            'number_min' => 1,
            'number_max' => $count,
            'hits_required' => $count,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::SalesOpen,
        ]);
        $numbers = [];
        for ($i = 1; $i <= $count; $i++) {
            $numbers[] = GameNumber::create([
                'game_id' => $game->id,
                'number' => $i,
                'status' => GameNumberStatus::Available,
            ]);
        }

        return [$game, $numbers];
    }

    private function createPendingOrderForUser(User $user): array
    {
        $game = Game::create([
            'slug' => 'vo-'.fake()->unique()->lexify('???????'),
            'name' => 'Rifa',
            'number_min' => 1,
            'number_max' => 5,
            'hits_required' => 5,
            'ticket_price_cents' => 500,
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
            'subtotal_cents' => 500,
            'total_cents' => 500,
            'currency' => 'PEN',
            'expires_at' => now()->addMinutes(10),
        ]);
        NumberReservation::create([
            'order_id' => $order->id,
            'game_number_id' => $gn->id,
        ]);
        Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 500,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Pending,
        ]);

        return [$order, $gn];
    }

    private function pdfFile(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'receipt.pdf',
            "%PDF-1.4\n%fake-content-for-testing\n".str_repeat('x', 200),
        );
    }

    // ── Reservations ─────────────────────────────────────────────────────────

    public function test_unverified_user_cannot_post_reservation(): void
    {
        [$game, $numbers] = $this->createGameWithNumbers();
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id],
        ], ['Idempotency-Key' => self::IDEM_KEY_A])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');
    }

    public function test_verified_user_can_post_reservation(): void
    {
        [$game, $numbers] = $this->createGameWithNumbers();
        $user = User::factory()->create(); // default: email_verified_at = now()
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/games/{$game->id}/reservations", [
            'game_number_ids' => [$numbers[0]->id],
        ], ['Idempotency-Key' => self::IDEM_KEY_A])
            ->assertCreated();
    }

    // ── Payment evidence ──────────────────────────────────────────────────────

    public function test_unverified_user_cannot_post_payment_evidence(): void
    {
        $user = User::factory()->unverified()->create();
        [$order] = $this->createPendingOrderForUser($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfFile(),
        ], ['Idempotency-Key' => self::IDEM_KEY_A])
            ->assertStatus(403)
            ->assertJsonPath('code', 'email_not_verified');
    }

    public function test_verified_user_can_post_payment_evidence(): void
    {
        $user = User::factory()->create();
        [$order] = $this->createPendingOrderForUser($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/me/orders/{$order->id}/payment-evidence", [
            'evidence' => $this->pdfFile(),
        ], ['Idempotency-Key' => self::IDEM_KEY_A])
            ->assertOk();
    }

    // ── Read endpoints remain accessible for unverified users ─────────────────

    public function test_unverified_user_can_get_my_orders(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/orders')->assertOk();
    }

    public function test_unverified_user_can_get_my_entries(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/entries')->assertOk();
    }

    public function test_unverified_user_can_get_public_games(): void
    {
        $user = User::factory()->unverified()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/public/games')->assertOk();
    }
}
