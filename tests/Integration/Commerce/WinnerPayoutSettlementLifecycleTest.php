<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Application\Actions\ConfirmWinnerPayoutReceiptAction;
use App\Modules\Commerce\Application\Actions\CloseGameFinancialAction;
use App\Modules\Commerce\Application\Actions\ExpireWinnerPayoutReceiptAction;
use App\Modules\Commerce\Application\Actions\OpenWinnerPayoutDisputeAction;
use App\Modules\Commerce\Application\Actions\ReconcileWinnerPayoutAction;
use App\Modules\Commerce\Application\DTOs\ConfirmWinnerPayoutReceiptData;
use App\Modules\Commerce\Application\DTOs\FinancialCloseGameData;
use App\Modules\Commerce\Application\DTOs\OpenWinnerPayoutDisputeData;
use App\Modules\Commerce\Application\DTOs\ReconcileWinnerPayoutData;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutExecutionAttemptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReceiptStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutReconciliationStatus;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDestination;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDocument;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDispute;
use App\Modules\Commerce\Domain\Models\WinnerPayoutReceipt;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GamePrizeFundingStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WinnerPayoutSettlementLifecycleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_player_confirmation_is_idempotently_represented_and_audited(): void
    {
        [$winner, $payout, $user] = $this->fixture();
        $action = $this->app->make(ConfirmWinnerPayoutReceiptAction::class);
        $data = new ConfirmWinnerPayoutReceiptData((string) $winner->id, (int) $user->id, hash('sha256', 'receipt-key'), hash('sha256', 'receipt-body'));

        DB::transaction(fn () => $action->executeWithinTransaction($data));

        $receipt = WinnerPayoutReceipt::query()->where('winner_payout_id', $payout->id)->firstOrFail();
        self::assertSame(WinnerPayoutReceiptStatus::Confirmed, $receipt->status);
        self::assertNotNull($receipt->confirmed_at);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'winner_payout_receipt_confirmed', 'aggregate_id' => $payout->id]);

        self::assertSame(1, WinnerPayoutReceipt::query()->where('status', WinnerPayoutReceiptStatus::Confirmed)->count());
    }

    public function test_unconfirmed_receipt_can_open_one_dispute_and_moves_payout_to_disputed(): void
    {
        [$winner, $payout, $user] = $this->fixture();
        $action = $this->app->make(OpenWinnerPayoutDisputeAction::class);
        $data = new OpenWinnerPayoutDisputeData((string) $winner->id, (int) $user->id, 'funds_not_received', 'No recibi el premio y necesito revision.', hash('sha256', 'dispute-key'), hash('sha256', 'dispute-body'));

        DB::transaction(fn () => $action->executeWithinTransaction($data));

        self::assertSame(WinnerPayoutStatus::Disputed, $payout->refresh()->status);
        self::assertSame(1, WinnerPayoutDispute::query()->where('winner_payout_id', $payout->id)->count());
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'winner_payout_disputed', 'aggregate_id' => $payout->id]);
    }

    public function test_player_receipt_endpoint_returns_the_public_claim_snapshot(): void
    {
        [$winner, $payout, $user] = $this->fixture();

        $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'receipt-api-key-000000000001')
            ->postJson("/api/v1/me/winnings/{$winner->id}/confirm-receipt", ['accepted' => true])
            ->assertOk()
            ->assertJsonPath('data.receipt_status', WinnerPayoutReceiptStatus::Confirmed->value)
            ->assertJsonMissingPath('data.payout_id')
            ->assertJsonMissingPath('data.external_reference');

        self::assertSame(WinnerPayoutReceiptStatus::Confirmed, WinnerPayoutReceipt::query()->where('winner_payout_id', $payout->id)->firstOrFail()->status);
    }

    public function test_player_dispute_endpoint_is_idempotent_and_hides_description_from_listing(): void
    {
        [$winner, $payout, $user] = $this->fixture();
        $payload = ['reason_code' => 'funds_not_received', 'description' => 'No recibi el premio y necesito revision.'];

        $first = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'dispute-api-key-000000000001')
            ->postJson("/api/v1/me/winnings/{$winner->id}/dispute", $payload)
            ->assertOk();
        $second = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', 'dispute-api-key-000000000001')
            ->postJson("/api/v1/me/winnings/{$winner->id}/dispute", $payload)
            ->assertOk();

        self::assertSame($first->json('data.dispute_status'), $second->json('data.dispute_status'));
        self::assertSame(1, WinnerPayoutDispute::query()->where('winner_payout_id', $payout->id)->count());
        self::assertArrayNotHasKey('description', $first->json('data'));
    }

    public function test_expired_confirmation_window_is_append_only_and_does_not_change_the_payout(): void
    {
        [, $payout] = $this->fixture(true);
        $expired = $this->app->make(ExpireWinnerPayoutReceiptAction::class)->executeBatch();

        self::assertSame(1, $expired);
        self::assertSame(WinnerPayoutStatus::Paid, $payout->refresh()->status);
        self::assertSame(WinnerPayoutReceiptStatus::WindowExpired, WinnerPayoutReceipt::query()->where('winner_payout_id', $payout->id)->firstOrFail()->status);
    }

    public function test_reconciliation_and_financial_close_require_paid_evidence_and_create_one_closure(): void
    {
        [$winner, $payout, $user] = $this->fixture();
        $admin = User::factory()->admin()->create();
        $this->attachPaidExecution($payout, $admin);
        $this->confirmReceipt($winner, $user);

        $reconciliation = $this->app->make(ReconcileWinnerPayoutAction::class);
        DB::transaction(fn () => $reconciliation->executeWithinTransaction(new ReconcileWinnerPayoutData((string) $payout->id, (int) $admin->id, 'amount_and_reference_match', 'MANUAL-REF', 'Verified against internal evidence.', hash('sha256', 'reconcile-key'), hash('sha256', 'reconcile-body'))));

        self::assertSame(WinnerPayoutReconciliationStatus::Matched, $payout->reconciliations()->firstOrFail()->status);
        $closure = $this->app->make(CloseGameFinancialAction::class);
        DB::transaction(fn () => $closure->executeWithinTransaction(new FinancialCloseGameData((string) $payout->game_id, (int) $admin->id, hash('sha256', 'close-key'), hash('sha256', 'close-body'))));

        self::assertDatabaseCount('game_financial_closures', 1);
        self::assertDatabaseHas('outbox_events', ['event_type' => 'game_financially_closed', 'aggregate_id' => (string) $payout->game_id]);
    }

    /** @return array{GameWinner, WinnerPayout, User} */
    private function fixture(bool $expired = false): array
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $now = now();
        $game = Game::create([
            'slug' => 'settlement-'.fake()->unique()->lexify('??????'),
            'name' => 'Settlement lifecycle',
            'number_min' => 1,
            'number_max' => 20,
            'hits_required' => 2,
            'ticket_price_cents' => 1000,
            'prize_cents' => 50000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => GameStatus::Completed,
            'started_at' => $now->copy()->subHour(),
            'completed_at' => $now,
        ]);
        GamePrizeFunding::create(['game_id' => $game->id, 'status' => GamePrizeFundingStatus::Reserved, 'amount_cents' => 50000, 'currency' => 'PEN', 'reserved_at' => $now]);
        $number = GameNumber::create(['game_id' => $game->id, 'number' => 7, 'status' => GameNumberStatus::Sold]);
        $entry = GameEntry::create(['game_id' => $game->id, 'game_number_id' => $number->id, 'user_id' => $user->id, 'status' => EntryStatus::Winner, 'confirmed_at' => $now]);
        $draw = GameDraw::create(['game_id' => $game->id, 'game_number_id' => $number->id, 'sequence' => 1, 'drawn_number' => 7, 'drawn_at' => $now, 'strategy' => 'test']);
        $winner = GameWinner::create(['game_id' => $game->id, 'game_entry_id' => $entry->id, 'game_draw_id' => $draw->id, 'game_number_id' => $number->id, 'user_id' => $user->id, 'winning_hits' => 2, 'won_at' => $now]);
        $claim = WinnerClaim::create(['game_winner_id' => $winner->id, 'winner_user_id' => $user->id, 'claim_reference' => 'CLAIM-'.fake()->unique()->lexify('????????'), 'status' => WinnerClaimStatus::Verified, 'verified_at' => $now]);
        $payout = WinnerPayout::create([
            'game_winner_id' => $winner->id,
            'game_id' => $game->id,
            'winner_claim_id' => $claim->id,
            'user_id' => $user->id,
            'amount_cents' => 50000,
            'currency' => 'PEN',
            'method' => 'manual',
            'external_reference' => 'MANUAL-REF',
            'status' => WinnerPayoutStatus::Paid,
            'executed_by_user_id' => $admin->id,
            'paid_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'idempotency_key_hash' => hash('sha256', 'payout-key'),
            'request_fingerprint' => hash('sha256', 'payout-body'),
        ]);
        WinnerPayoutReceipt::create([
            'winner_payout_id' => $payout->id,
            'winner_user_id' => $user->id,
            'status' => WinnerPayoutReceiptStatus::Pending,
            'confirmation_window_started_at' => $now,
            'confirmation_expires_at' => $expired ? $now->copy()->subMinute() : $now->copy()->addDays(7),
            'is_legacy' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$winner, $payout, $user];
    }

    private function confirmReceipt(GameWinner $winner, User $user): void
    {
        DB::transaction(fn () => $this->app->make(ConfirmWinnerPayoutReceiptAction::class)->executeWithinTransaction(new ConfirmWinnerPayoutReceiptData((string) $winner->id, (int) $user->id, hash('sha256', 'confirm-close-key'), hash('sha256', 'confirm-close-body'))));
    }

    private function attachPaidExecution(WinnerPayout $payout, User $admin): void
    {
        $now = now();
        $destination = WinnerPayoutDestination::create([
            'winner_payout_id' => $payout->id,
            'version' => 1,
            'method' => 'cash',
            'destination_payload_encrypted' => ['label' => 'manual'],
            'destination_masked' => 'manual',
            'created_by_user_id' => $admin->id,
            'created_at' => $now,
        ]);
        $attempt = $payout->executionAttempts()->create([
            'attempt_number' => 1,
            'status' => WinnerPayoutExecutionAttemptStatus::Paid,
            'destination_id' => $destination->id,
            'started_by_user_id' => $admin->id,
            'completed_by_user_id' => $admin->id,
            'external_reference_encrypted' => 'MANUAL-REF',
            'external_reference_masked' => '****-REF',
            'started_at' => $now,
            'paid_at' => $now,
            'idempotency_key_hash' => hash('sha256', 'attempt-key'),
            'request_fingerprint' => hash('sha256', 'attempt-body'),
            'created_at' => $now,
        ]);
        WinnerPayoutDocument::create([
            'payout_id' => $payout->id,
            'document_type' => 'execution_proof',
            'execution_attempt_id' => $attempt->id,
            'disk' => 'winner_payouts',
            'path' => 'test/proof.pdf',
            'original_filename' => 'proof.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'sha256' => hash('sha256', 'proof'),
            'uploaded_by' => $admin->id,
            'created_at' => $now,
        ]);
        $payout->current_execution_attempt_id = $attempt->id;
        $payout->current_destination_id = $destination->id;
        $payout->save();
    }
}
