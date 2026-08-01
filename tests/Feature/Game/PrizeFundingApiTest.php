<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFundingDocument;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFundingEvent;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PrizeFundingApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('game_prize_fundings');
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $game = $this->game();

        $this->postJson("/api/v1/admin/games/{$game->id}/prize-funding", [], [
            'Idempotency-Key' => 'funding-key-unauthenticated',
        ])
            ->assertUnauthorized();
    }

    public function test_player_cannot_record_funding(): void
    {
        $game = $this->game();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/admin/games/{$game->id}/prize-funding", [], [
            'Idempotency-Key' => 'funding-key-player-0001',
        ])
            ->assertForbidden();
    }

    public function test_idempotency_key_and_document_are_required(): void
    {
        $game = $this->game();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->post("/api/v1/admin/games/{$game->id}/prize-funding", [
            'document' => $this->pdfUpload(),
        ])->assertBadRequest();

        $this->postJson("/api/v1/admin/games/{$game->id}/prize-funding", [], [
            'Idempotency-Key' => 'funding-key-validation-0001',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('document');
    }

    public function test_admin_records_funding_using_game_amount_and_currency(): void
    {
        $admin = User::factory()->admin()->create();
        $game = $this->game();
        Sanctum::actingAs($admin);

        $response = $this->post("/api/v1/admin/games/{$game->id}/prize-funding", [
            'document' => $this->pdfUpload(),
            'amount_cents' => 1,
            'currency' => 'USD',
        ], ['Idempotency-Key' => 'funding-key-record-0001']);

        $response->assertOk()
            ->assertJsonPath('data.status', GamePrizeFundingStatus::Funded->value)
            ->assertJsonPath('data.amount_cents', 2000)
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonMissingPath('data.documents.0.path')
            ->assertJsonMissingPath('data.documents.0.sha256');

        $funding = GamePrizeFunding::query()->where('game_id', $game->id)->firstOrFail();

        $this->assertSame(GamePrizeFundingStatus::Funded, $funding->status);
        $this->assertSame(2000, $funding->amount_cents);
        $this->assertSame('PEN', $funding->currency);
        $this->assertSame(1, GamePrizeFundingDocument::query()->where('game_prize_funding_id', $funding->id)->count());
        $this->assertSame(1, GamePrizeFundingEvent::query()
            ->where('game_prize_funding_id', $funding->id)
            ->where('event_type', GamePrizeFundingEventType::FundingRecorded)
            ->count());
    }

    public function test_backend_derives_sha256_and_keeps_evidence_append_only(): void
    {
        $admin = User::factory()->admin()->create();
        $game = $this->game();
        $content = "%PDF-1.4\ncanonical-funding-proof";
        Sanctum::actingAs($admin);

        $this->post("/api/v1/admin/games/{$game->id}/prize-funding", [
            'document' => $this->pdfUpload($content),
        ], ['Idempotency-Key' => 'funding-key-hash-0001'])->assertOk();

        $funding = GamePrizeFunding::query()->where('game_id', $game->id)->firstOrFail();
        $document = GamePrizeFundingDocument::query()
            ->where('game_prize_funding_id', $funding->id)
            ->firstOrFail();
        $this->assertSame(hash('sha256', $content), $document->sha256);
        $this->expectException(ImmutableModelException::class);
        $document->update(['mime_type' => 'text/plain']);
    }

    public function test_funding_audit_event_is_append_only(): void
    {
        $admin = User::factory()->admin()->create();
        $game = $this->game();
        Sanctum::actingAs($admin);

        $this->post("/api/v1/admin/games/{$game->id}/prize-funding", [
            'document' => $this->pdfUpload(),
        ], ['Idempotency-Key' => 'funding-key-event-0001'])->assertOk();

        $event = GamePrizeFundingEvent::query()
            ->where('game_prize_funding_id', GamePrizeFunding::query()->where('game_id', $game->id)->value('id'))
            ->where('event_type', GamePrizeFundingEventType::FundingRecorded)
            ->firstOrFail();

        $this->expectException(ImmutableModelException::class);
        $event->delete();
    }

    public function test_identical_replay_does_not_duplicate_document_or_event(): void
    {
        $admin = User::factory()->admin()->create();
        $game = $this->game();
        Sanctum::actingAs($admin);
        $headers = ['Idempotency-Key' => 'funding-key-replay-0001'];

        $this->post("/api/v1/admin/games/{$game->id}/prize-funding", [
            'document' => $this->pdfUpload(),
        ], $headers)->assertOk();

        $this->post("/api/v1/admin/games/{$game->id}/prize-funding", [
            'document' => $this->pdfUpload(),
        ], $headers)->assertOk();

        $funding = GamePrizeFunding::query()->where('game_id', $game->id)->firstOrFail();

        $this->assertSame(1, GamePrizeFundingDocument::query()
            ->where('game_prize_funding_id', $funding->id)
            ->count());
        $this->assertSame(1, GamePrizeFundingEvent::query()
            ->where('game_prize_funding_id', $funding->id)
            ->where('event_type', GamePrizeFundingEventType::FundingRecorded)
            ->count());
        $this->assertCount(1, Storage::disk('game_prize_fundings')->allFiles());
    }

    public function test_same_key_with_different_document_returns_conflict(): void
    {
        $admin = User::factory()->admin()->create();
        $game = $this->game();
        Sanctum::actingAs($admin);
        $headers = ['Idempotency-Key' => 'funding-key-conflict-01'];

        $this->post("/api/v1/admin/games/{$game->id}/prize-funding", [
            'document' => $this->pdfUpload('%PDF-1.4 first'),
        ], $headers)->assertOk();

        $this->post("/api/v1/admin/games/{$game->id}/prize-funding", [
            'document' => $this->pdfUpload('%PDF-1.4 second'),
        ], $headers)->assertStatus(409)
            ->assertJsonPath('error', 'idempotency_key_mismatch');

        $this->assertCount(1, Storage::disk('game_prize_fundings')->allFiles());
    }

    public function test_get_endpoint_is_admin_only_and_returns_safe_metadata(): void
    {
        $game = $this->game(GameStatus::Published, GamePrizeFundingStatus::Funded);
        $funding = GamePrizeFunding::query()->where('game_id', $game->id)->firstOrFail();
        GamePrizeFundingDocument::create([
            'game_prize_funding_id' => $funding->id,
            'disk' => 'game_prize_fundings',
            'path' => 'games/'.$game->id.'/proof.pdf',
            'original_filename' => 'proof.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => str_repeat('a', 64),
            'uploaded_by_user_id' => User::factory()->admin()->create()->id,
        ]);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/admin/games/{$game->id}/prize-funding")
            ->assertOk()
            ->assertJsonPath('data.status', GamePrizeFundingStatus::Funded->value)
            ->assertJsonMissingPath('data.documents.0.path')
            ->assertJsonMissingPath('data.documents.0.sha256');

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/v1/admin/games/{$game->id}/prize-funding")
            ->assertForbidden();
    }

    public function test_legacy_unverified_funding_can_be_recorded_without_claim_or_payout(): void
    {
        $admin = User::factory()->admin()->create();
        $game = $this->game(GameStatus::SalesClosed, GamePrizeFundingStatus::LegacyUnverified);
        Sanctum::actingAs($admin);

        $this->post("/api/v1/admin/games/{$game->id}/prize-funding", [
            'document' => $this->pdfUpload(),
        ], ['Idempotency-Key' => 'funding-key-legacy-0001'])->assertOk();

        $this->assertDatabaseHas('game_prize_fundings', [
            'game_id' => $game->id,
            'status' => GamePrizeFundingStatus::Funded->value,
        ]);
    }

    private function game(
        GameStatus $status = GameStatus::Draft,
        GamePrizeFundingStatus $fundingStatus = GamePrizeFundingStatus::Unfunded,
    ): Game {
        $game = Game::create([
            'slug' => 'funding-'.fake()->unique()->lexify('??????'),
            'name' => 'Funding test',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 2,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => $status,
        ]);

        GamePrizeFunding::create([
            'game_id' => $game->id,
            'status' => $fundingStatus,
            'amount_cents' => $game->prize_cents,
            'currency' => $game->currency,
        ]);

        return $game;
    }

    private function pdfUpload(string $content = "%PDF-1.4\nfunding-proof"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('funding-proof.pdf', $content);
    }
}
