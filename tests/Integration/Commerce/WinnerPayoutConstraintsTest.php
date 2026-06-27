<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDocument;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WinnerPayoutConstraintsTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{User, User, Game, GameWinner}
     */
    private function setupGameWithWinner(): array
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();

        $game = Game::create([
            'slug' => 'wpc-'.fake()->unique()->lexify('?????'),
            'name' => 'WPC',
            'number_min' => 1, 'number_max' => 10, 'hits_required' => 3,
            'ticket_price_cents' => 500, 'prize_cents' => 50000,
            'currency' => 'PEN', 'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false, 'status' => GameStatus::Completed,
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
            'confirmed_at' => now()->subMinutes(10),
        ]);

        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $gn->id,
            'sequence' => 1,
            'drawn_number' => 1,
            'drawn_at' => now()->subMinutes(5),
            'strategy' => 'random',
            'created_at' => now()->subMinutes(5),
        ]);

        $winner = GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $gn->id,
            'user_id' => $buyer->id,
            'winning_hits' => 3,
            'won_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
        ]);

        return [$admin, $buyer, $game, $winner];
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayoutRow(string $gameWinnerId, string $gameId, int $userId, int $processedBy, ?string $keyHash = null): array
    {
        $keyHash ??= hash('sha256', 'test-key-'.fake()->uuid());

        return [
            'id' => (string) Str::uuid7(),
            'game_winner_id' => $gameWinnerId,
            'game_id' => $gameId,
            'user_id' => $userId,
            'amount_cents' => 50000,
            'currency' => 'PEN',
            'method' => 'manual',
            'external_reference' => 'OP-TEST-CONSTRAINT-001',
            'notes' => null,
            'idempotency_key_hash' => $keyHash,
            'request_fingerprint' => hash('sha256', 'fingerprint-'.fake()->uuid()),
            'processed_by_user_id' => $processedBy,
            'processed_at' => now()->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validDocumentRow(string $payoutId, int $uploadedBy, ?string $sha256 = null): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'payout_id' => $payoutId,
            'disk' => 'winner_payouts',
            'path' => 'payouts/2026/06/27/test-doc.pdf',
            'original_filename' => 'comprobante.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => $sha256 ?? hash('sha256', 'doc-content-'.fake()->uuid()),
            'uploaded_by' => $uploadedBy,
            'created_at' => now()->toIso8601String(),
        ];
    }

    // ── WinnerPayout constraints ──────────────────────────────────────────────

    public function test_inserts_valid_payout_row(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();

        DB::table('winner_payouts')->insert($this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id));

        $this->assertSame(1, DB::table('winner_payouts')->where('game_id', $game->id)->count());
    }

    public function test_unique_game_winner_id_rejects_duplicate_payout(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();

        DB::table('winner_payouts')->insert($this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id));

        $this->expectException(QueryException::class);

        DB::table('winner_payouts')->insert($this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id));
    }

    public function test_unique_idempotency_key_hash_rejects_duplicate(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();

        // Create a second winner for a second game
        [$admin2, $buyer2, $game2, $winner2] = $this->setupGameWithWinner();

        $keyHash = hash('sha256', 'shared-payout-key');
        DB::table('winner_payouts')->insert($this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id, $keyHash));

        $this->expectException(QueryException::class);

        DB::table('winner_payouts')->insert($this->validPayoutRow($winner2->id, $game2->id, $buyer2->id, $admin2->id, $keyHash));
    }

    public function test_amount_check_rejects_zero(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();

        $this->expectException(QueryException::class);

        DB::table('winner_payouts')->insert([
            ...$this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id),
            'amount_cents' => 0,
        ]);
    }

    public function test_amount_check_rejects_negative(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();

        $this->expectException(QueryException::class);

        DB::table('winner_payouts')->insert([
            ...$this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id),
            'amount_cents' => -1,
        ]);
    }

    public function test_currency_check_rejects_lowercase(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();

        $this->expectException(QueryException::class);

        DB::table('winner_payouts')->insert([
            ...$this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id),
            'currency' => 'pen',
        ]);
    }

    public function test_method_check_rejects_non_manual(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();

        $this->expectException(QueryException::class);

        DB::table('winner_payouts')->insert([
            ...$this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id),
            'method' => 'automatic',
        ]);
    }

    public function test_external_reference_check_rejects_blank(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();

        $this->expectException(QueryException::class);

        DB::table('winner_payouts')->insert([
            ...$this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id),
            'external_reference' => '   ',
        ]);
    }

    public function test_key_hash_check_rejects_non_hex(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();

        $this->expectException(QueryException::class);

        DB::table('winner_payouts')->insert([
            ...$this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id),
            'idempotency_key_hash' => str_repeat('z', 64),
        ]);
    }

    public function test_fingerprint_check_rejects_non_hex(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();

        $this->expectException(QueryException::class);

        DB::table('winner_payouts')->insert([
            ...$this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id),
            'request_fingerprint' => str_repeat('z', 64),
        ]);
    }

    // ── WinnerPayout immutability ─────────────────────────────────────────────

    public function test_winner_payout_model_is_append_only_update_throws(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        DB::table('winner_payouts')->insert($this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id));

        $payout = WinnerPayout::query()->where('game_id', $game->id)->firstOrFail();

        $this->expectException(ImmutableModelException::class);

        $payout->forceFill(['external_reference' => 'changed'])->save();
    }

    public function test_winner_payout_model_is_append_only_delete_throws(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        DB::table('winner_payouts')->insert($this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id));

        $payout = WinnerPayout::query()->where('game_id', $game->id)->firstOrFail();

        $this->expectException(ImmutableModelException::class);

        $payout->delete();
    }

    // ── WinnerPayoutDocument constraints ─────────────────────────────────────

    public function test_inserts_valid_document_row(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        $payoutRow = $this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id);
        DB::table('winner_payouts')->insert($payoutRow);

        DB::table('winner_payout_documents')->insert($this->validDocumentRow($payoutRow['id'], $admin->id));

        $this->assertSame(1, DB::table('winner_payout_documents')->count());
    }

    public function test_document_size_check_rejects_zero(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        $payoutRow = $this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id);
        DB::table('winner_payouts')->insert($payoutRow);

        $this->expectException(QueryException::class);

        DB::table('winner_payout_documents')->insert([
            ...$this->validDocumentRow($payoutRow['id'], $admin->id),
            'size_bytes' => 0,
        ]);
    }

    public function test_document_sha256_check_rejects_non_hex(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        $payoutRow = $this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id);
        DB::table('winner_payouts')->insert($payoutRow);

        $this->expectException(QueryException::class);

        DB::table('winner_payout_documents')->insert([
            ...$this->validDocumentRow($payoutRow['id'], $admin->id),
            'sha256' => str_repeat('z', 64),
        ]);
    }

    public function test_document_disk_check_rejects_blank(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        $payoutRow = $this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id);
        DB::table('winner_payouts')->insert($payoutRow);

        $this->expectException(QueryException::class);

        DB::table('winner_payout_documents')->insert([
            ...$this->validDocumentRow($payoutRow['id'], $admin->id),
            'disk' => '   ',
        ]);
    }

    public function test_document_path_check_rejects_blank(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        $payoutRow = $this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id);
        DB::table('winner_payouts')->insert($payoutRow);

        $this->expectException(QueryException::class);

        DB::table('winner_payout_documents')->insert([
            ...$this->validDocumentRow($payoutRow['id'], $admin->id),
            'path' => '   ',
        ]);
    }

    public function test_document_filename_check_rejects_blank(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        $payoutRow = $this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id);
        DB::table('winner_payouts')->insert($payoutRow);

        $this->expectException(QueryException::class);

        DB::table('winner_payout_documents')->insert([
            ...$this->validDocumentRow($payoutRow['id'], $admin->id),
            'original_filename' => '   ',
        ]);
    }

    public function test_document_mime_check_rejects_blank(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        $payoutRow = $this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id);
        DB::table('winner_payouts')->insert($payoutRow);

        $this->expectException(QueryException::class);

        DB::table('winner_payout_documents')->insert([
            ...$this->validDocumentRow($payoutRow['id'], $admin->id),
            'mime_type' => '   ',
        ]);
    }

    // ── WinnerPayoutDocument immutability ─────────────────────────────────────

    public function test_winner_payout_document_model_is_append_only_update_throws(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        $payoutRow = $this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id);
        DB::table('winner_payouts')->insert($payoutRow);
        DB::table('winner_payout_documents')->insert($this->validDocumentRow($payoutRow['id'], $admin->id));

        $doc = WinnerPayoutDocument::query()->firstOrFail();

        $this->expectException(ImmutableModelException::class);

        $doc->forceFill(['original_filename' => 'changed.pdf'])->save();
    }

    public function test_winner_payout_document_model_is_append_only_delete_throws(): void
    {
        [$admin, $buyer, $game, $winner] = $this->setupGameWithWinner();
        $payoutRow = $this->validPayoutRow($winner->id, $game->id, $buyer->id, $admin->id);
        DB::table('winner_payouts')->insert($payoutRow);
        DB::table('winner_payout_documents')->insert($this->validDocumentRow($payoutRow['id'], $admin->id));

        $doc = WinnerPayoutDocument::query()->firstOrFail();

        $this->expectException(ImmutableModelException::class);

        $doc->delete();
    }
}
