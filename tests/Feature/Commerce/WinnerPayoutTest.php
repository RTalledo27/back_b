<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Events\WinnerPayoutRegistered;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDocument;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WinnerPayoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const KEY_A = 'payout-key-aaaaaaaaaaaaaaaa';

    private const KEY_B = 'payout-key-bbbbbbbbbbbbbbbb';

    /**
     * Creates a Completed game with a GameWinner.
     *
     * @return array{User, User, Game, GameWinner}
     */
    private function setupCompletedGame(): array
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $game = Game::create([
            'slug' => 'wp-'.fake()->unique()->lexify('?????'),
            'name' => 'Winner Payout Test Game',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 3,
            'ticket_price_cents' => 1000,
            'prize_cents' => 50000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => GameStatus::Completed,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinutes(30),
        ]);

        $gn = GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => GameNumberStatus::Sold,
        ]);

        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'user_id' => $buyer->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => now()->subMinutes(25),
        ]);

        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'sequence' => 1,
            'drawn_number' => 1,
            'drawn_at' => now()->subMinutes(10),
            'strategy' => 'random',
            'created_at' => now()->subMinutes(10),
        ]);

        $winner = GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $gn->id,
            'user_id' => $buyer->id,
            'winning_hits' => 3,
            'won_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(5),
        ]);

        return [$buyer, $admin, $game, $winner];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function postPayout(Game $game, string $idempotencyKey, array $extra = []): TestResponse
    {
        Storage::fake('winner_payouts');

        $file = $extra['document'] ?? UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');

        return $this->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", array_merge([
                'external_reference' => 'OP-TEST-001',
                'notes' => 'Transferencia bancaria completada.',
                'document' => $file,
            ], $extra));
    }

    // ── 1. Auth & authorization ──────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        [, , $game] = $this->setupCompletedGame();

        $this->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [], [
            'Idempotency-Key' => self::KEY_A,
        ])->assertStatus(401);
    }

    public function test_player_cannot_process_payout(): void
    {
        [$buyer, , $game] = $this->setupCompletedGame();
        Sanctum::actingAs($buyer);

        $this->postPayout($game, self::KEY_A)->assertStatus(403);
    }

    // ── 2. Happy path ─────────────────────────────────────────────────────────

    public function test_admin_can_process_payout_successfully(): void
    {
        [, $admin, $game, $winner] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $response = $this->postPayout($game, self::KEY_A);

        $response->assertStatus(200)
            ->assertJsonPath('data.game_id', (string) $game->id)
            ->assertJsonPath('data.game_winner_id', (string) $winner->id)
            ->assertJsonPath('data.amount_cents', 50000)
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonPath('data.method', 'manual')
            ->assertJsonPath('data.external_reference', 'OP-TEST-001')
            ->assertJsonPath('data.was_already_processed', false)
            ->assertJsonStructure([
                'data' => [
                    'id', 'game_id', 'game_winner_id', 'user_id',
                    'amount_cents', 'currency', 'method',
                    'external_reference', 'notes',
                    'processed_by_user_id', 'processed_at', 'created_at',
                    'document' => ['id', 'original_filename', 'mime_type', 'size_bytes', 'created_at'],
                    'was_already_processed',
                ],
            ]);

        $this->assertSame(1, WinnerPayout::query()->where('game_id', $game->id)->count());
        $this->assertSame(1, WinnerPayoutDocument::query()->count());
        $this->assertSame(1, GameEvent::query()->where('type', GameEventType::PayoutPaid)->count());
    }

    // ── 3. Validation ─────────────────────────────────────────────────────────

    public function test_payout_requires_idempotency_key_header(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
            'external_reference' => 'OP-001',
            'document' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(400); // EnsureIdempotencyKeyHeader throws BadRequestHttpException (400)
    }

    public function test_payout_requires_external_reference(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        Storage::fake('winner_payouts');
        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'document' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['external_reference']);
    }

    public function test_payout_external_reference_cannot_be_empty(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        Storage::fake('winner_payouts');
        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => '',
                'document' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['external_reference']);
    }

    public function test_payout_requires_document(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        Storage::fake('winner_payouts');
        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-001',
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['document']);
    }

    public function test_payout_rejects_disallowed_mime_type(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        Storage::fake('winner_payouts');
        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-001',
                'document' => UploadedFile::fake()->create('data.csv', 100, 'text/csv'),
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['document']);
    }

    public function test_payout_rejects_oversized_document(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        Storage::fake('winner_payouts');
        // 10241 KB = just over 10 MB limit
        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-001',
                'document' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'),
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['document']);
    }

    // ── 4. Snapshot invariants ────────────────────────────────────────────────

    public function test_payout_ignores_amount_cents_in_body(): void
    {
        [, $admin, $game, $winner] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $response = $this->postPayout($game, self::KEY_A, ['amount_cents' => 999]);

        $response->assertStatus(200)
            ->assertJsonPath('data.amount_cents', 50000); // game.prize_cents, not 999
    }

    public function test_payout_ignores_user_id_in_body(): void
    {
        [$buyer, $admin, $game, $winner] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $response = $this->postPayout($game, self::KEY_A, ['user_id' => 99999]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user_id', $buyer->id); // game_winner.user_id
    }

    // ── 5. Domain invariants ──────────────────────────────────────────────────

    public function test_game_not_completed_returns_422(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        $game->update(['status' => GameStatus::SalesOpen]);
        Sanctum::actingAs($admin);

        $this->postPayout($game, self::KEY_A)
            ->assertStatus(422)
            ->assertJsonPath('error', 'payout_not_processable')
            ->assertJsonPath('reason', 'game_not_completed');
    }

    public function test_game_cancelled_returns_422(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        $game->update(['status' => GameStatus::Cancelled]);
        Sanctum::actingAs($admin);

        $this->postPayout($game, self::KEY_A)
            ->assertStatus(422)
            ->assertJsonPath('error', 'payout_not_processable')
            ->assertJsonPath('reason', 'game_not_completed');
    }

    public function test_game_without_winner_returns_422(): void
    {
        [, $admin] = $this->setupCompletedGame();

        // Create a separate Completed game with NO GameWinner
        $newGame = Game::create([
            'slug' => 'wp-nw-'.fake()->unique()->lexify('?????'),
            'name' => 'No Winner Game',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 3,
            'ticket_price_cents' => 1000, 'prize_cents' => 50000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::Completed,
        ]);
        Sanctum::actingAs($admin);

        $this->postPayout($newGame, self::KEY_A)
            ->assertStatus(422)
            ->assertJsonPath('error', 'payout_not_processable')
            ->assertJsonPath('reason', 'winner_not_found');
    }

    // ── 6. Immutability checks ────────────────────────────────────────────────

    public function test_payout_does_not_modify_game(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $statusBefore = $game->status;
        $prizeBefore = $game->prize_cents;

        $this->postPayout($game, self::KEY_A)->assertStatus(200);

        $game->refresh();
        $this->assertSame($statusBefore, $game->status);
        $this->assertSame($prizeBefore, $game->prize_cents);
    }

    public function test_payout_does_not_modify_game_winner(): void
    {
        [$buyer, $admin, $game, $winner] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $wonAtBefore = $winner->won_at->toIso8601String();
        $userIdBefore = $winner->user_id;

        $this->postPayout($game, self::KEY_A)->assertStatus(200);

        $winner->refresh();
        $this->assertSame($wonAtBefore, $winner->won_at->toIso8601String());
        $this->assertSame($userIdBefore, $winner->user_id);
    }

    public function test_payout_does_not_modify_game_entry(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $entry = GameEntry::query()->where('game_id', $game->id)->firstOrFail();
        $statusBefore = $entry->status;

        $this->postPayout($game, self::KEY_A)->assertStatus(200);

        $this->assertSame($statusBefore, $entry->refresh()->status);
    }

    // ── 7. GameEvent payload ──────────────────────────────────────────────────

    public function test_payout_creates_game_event_with_correct_payload(): void
    {
        [$buyer, $admin, $game, $winner] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $response = $this->postPayout($game, self::KEY_A)->assertStatus(200);
        $payoutId = $response->json('data.id');

        $event = GameEvent::query()->where('type', GameEventType::PayoutPaid)->firstOrFail();
        $payload = $event->payload;

        $this->assertSame($payoutId, $payload['payout_id']);
        $this->assertSame((string) $winner->id, $payload['game_winner_id']);
        $this->assertSame($buyer->id, $payload['winner_user_id']);
        $this->assertSame((int) $admin->id, $payload['actor_user_id']);
        $this->assertSame(50000, $payload['amount_cents']);
        $this->assertSame('PEN', $payload['currency']);
        $this->assertSame('OP-TEST-001', $payload['external_reference']);

        // Must NOT contain sensitive fields
        $this->assertArrayNotHasKey('idempotency_key_hash', $payload);
        $this->assertArrayNotHasKey('request_fingerprint', $payload);
        $this->assertArrayNotHasKey('disk', $payload);
        $this->assertArrayNotHasKey('path', $payload);
        $this->assertArrayNotHasKey('sha256', $payload);
    }

    // ── 8. Idempotency ────────────────────────────────────────────────────────

    public function test_same_key_same_fingerprint_returns_existing_payout(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');

        Storage::fake('winner_payouts');
        $r1 = $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001',
                'document' => $file,
            ])->assertStatus(200);

        Storage::fake('winner_payouts');
        $r2 = $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001',
                'document' => $file,
            ])->assertStatus(200);

        $this->assertSame($r1->json('data.id'), $r2->json('data.id'));
        $this->assertTrue($r2->json('data.was_already_processed'));
        $this->assertSame(1, WinnerPayout::query()->where('game_id', $game->id)->count());
        $this->assertSame(1, WinnerPayoutDocument::query()->count(), 'No second document must be created on idempotent replay');
    }

    public function test_same_key_different_fingerprint_returns_409(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');

        Storage::fake('winner_payouts');
        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001',
                'document' => $file,
            ])->assertStatus(200);

        Storage::fake('winner_payouts');
        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-DIFFERENT',
                'document' => $file,
            ])->assertStatus(409)
            ->assertJsonPath('error', 'idempotency_key_mismatch');
    }

    public function test_same_key_different_document_sha_returns_409(): void
    {
        // Same idempotency key + same external_reference, but different file (different SHA256).
        // The document SHA is part of the fingerprint, so this must trigger a conflict.
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        // Create two fake files; then overwrite file2's physical content so its SHA256
        // differs from file1 while still passing mimes:pdf validation (test=true mode).
        $file1 = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');
        $file2 = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');
        file_put_contents($file2->getRealPath(), 'ALTERNATIVE-CONTENT-FOR-SHA256-COLLISION-TEST'); // different SHA256

        Storage::fake('winner_payouts');
        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001',
                'document' => $file1,
            ])->assertStatus(200);

        Storage::fake('winner_payouts');
        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001', // same reference — only the document SHA differs
                'document' => $file2,
            ])->assertStatus(409)
            ->assertJsonPath('error', 'idempotency_key_mismatch');
    }

    public function test_different_key_after_payout_exists_returns_existing(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');

        Storage::fake('winner_payouts');
        $r1 = $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001',
                'document' => $file,
            ])->assertStatus(200);

        Storage::fake('winner_payouts');
        $r2 = $this->withHeaders(['Idempotency-Key' => self::KEY_B])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001',
                'document' => $file,
            ])->assertStatus(200);

        $this->assertSame($r1->json('data.id'), $r2->json('data.id'));
        $this->assertTrue($r2->json('data.was_already_processed'));
        $this->assertSame(1, WinnerPayout::query()->where('game_id', $game->id)->count());
        $this->assertSame(1, WinnerPayoutDocument::query()->count(), 'A different key returning an existing payout must not create a second document');
    }

    // ── 9. GET endpoint ───────────────────────────────────────────────────────

    public function test_get_returns_existing_payout(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $postResponse = $this->postPayout($game, self::KEY_A)->assertStatus(200);

        $getResponse = $this->getJson("/api/v1/admin/games/{$game->id}/winner/payout")
            ->assertStatus(200);

        $this->assertSame($postResponse->json('data.id'), $getResponse->json('data.id'));
        $this->assertSame($postResponse->json('data.amount_cents'), $getResponse->json('data.amount_cents'));
    }

    public function test_get_without_payout_returns_404(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/admin/games/{$game->id}/winner/payout")
            ->assertStatus(404)
            ->assertJsonPath('error', 'payout_not_found');
    }

    // ── 10. Security — no sensitive fields ────────────────────────────────────

    public function test_resource_does_not_expose_sensitive_fields(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $response = $this->postPayout($game, self::KEY_A)->assertStatus(200);

        $body = json_encode($response->json()) ?: '';

        $this->assertStringNotContainsString('idempotency_key_hash', $body);
        $this->assertStringNotContainsString('request_fingerprint', $body);
        $this->assertStringNotContainsString('"disk"', $body);
        $this->assertStringNotContainsString('"path"', $body);
        $this->assertStringNotContainsString('"sha256"', $body);
    }

    // ── 11. Storage ───────────────────────────────────────────────────────────

    public function test_document_stored_in_private_disk(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        Storage::fake('winner_payouts');

        $file = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');

        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001',
                'document' => $file,
            ])->assertStatus(200);

        $doc = WinnerPayoutDocument::query()->firstOrFail();
        Storage::disk('winner_payouts')->assertExists($doc->path);
    }

    // ── 12. Post-commit event ─────────────────────────────────────────────────

    public function test_winner_payout_registered_event_dispatched_post_commit(): void
    {
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        Event::fake([WinnerPayoutRegistered::class]);

        $this->postPayout($game, self::KEY_A)->assertStatus(200);

        Event::assertDispatched(WinnerPayoutRegistered::class, function (WinnerPayoutRegistered $event) use ($game): bool {
            return $event->gameId === (string) $game->id
                && $event->amountCents === 50000
                && $event->currency === 'PEN';
        });
    }

    // ── 13. Storage compensation ──────────────────────────────────────────────

    public function test_file_is_cleaned_up_when_action_fails(): void
    {
        // A Completed game with no GameWinner triggers PayoutNotProcessable::winnerNotFound
        // inside the transaction — AFTER the file was already stored to disk.
        // The controller's compensation block must delete the orphan file.
        [, $admin] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        $noWinnerGame = Game::create([
            'slug' => 'wp-cleanup-'.fake()->unique()->lexify('?????'),
            'name' => 'Cleanup Test Game',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 3,
            'ticket_price_cents' => 1000, 'prize_cents' => 50000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::Completed,
            'scheduled_start_at' => now()->subHour(),
            'started_at' => now()->subMinutes(30),
        ]);

        Storage::fake('winner_payouts');

        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$noWinnerGame->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001',
                'document' => UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf'),
            ])->assertStatus(422)
            ->assertJsonPath('reason', 'winner_not_found');

        // Compensation: the file stored before the transaction must have been deleted.
        // If cleanup itself fails, report() is called — the internal path is never exposed
        // in the HTTP response.
        $this->assertEmpty(
            Storage::disk('winner_payouts')->allFiles(),
            'Orphan file must be deleted after action throws',
        );
        $this->assertSame(0, WinnerPayout::query()->count());
    }

    public function test_orphan_file_is_cleaned_up_on_idempotent_replay(): void
    {
        // Two POSTs with the same key and identical file content → same SHA256 → same fingerprint.
        // The second POST triggers an idempotent replay: the controller stores the new file
        // and then immediately deletes it because was_already_processed=true.
        [, $admin, $game] = $this->setupCompletedGame();
        Sanctum::actingAs($admin);

        // Single fake disk for the whole test — not reset between calls.
        Storage::fake('winner_payouts');

        // Both files use identical size → identical content (str_repeat('0', 102400))
        // → same SHA256 → same fingerprint → idempotent replay on the second POST.
        $file1 = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf');
        $file2 = UploadedFile::fake()->create('comprobante.pdf', 100, 'application/pdf'); // same SHA256

        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001',
                'document' => $file1,
            ])->assertStatus(200)
            ->assertJsonPath('data.was_already_processed', false);

        $this->assertCount(1, Storage::disk('winner_payouts')->allFiles(), '1 file after first POST');
        $this->assertSame(1, WinnerPayoutDocument::query()->count());

        $this->withHeaders(['Idempotency-Key' => self::KEY_A])
            ->postJson("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OP-TEST-001', // same fingerprint
                'document' => $file2,
            ])->assertStatus(200)
            ->assertJsonPath('data.was_already_processed', true);

        // The orphan upload from the second POST must have been deleted.
        $this->assertCount(1, Storage::disk('winner_payouts')->allFiles(),
            'Orphan file from idempotent replay must be deleted; only the original must remain');
        $this->assertSame(1, WinnerPayoutDocument::query()->count(),
            'No second document must be created on idempotent replay');
    }
}
