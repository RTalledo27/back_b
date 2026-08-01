<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Enums\UserRole;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\Commerce\Domain\Models\WinnerPayoutEvent;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WinnerPayoutDualControlApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_payout_uses_completed_game_snapshot_and_requires_dual_control(): void
    {
        [$winnerUser, $maker, $checker, $game] = $this->completedGameWithVerifiedClaim();

        $create = $this->actingAs($maker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-create-0001')
            ->postJson("/api/v1/admin/games/{$game->id}/winner-payouts", [
                'destination' => [
                    'method' => 'yape',
                    'phone' => '999111222',
                ],
                'amount_cents' => 1,
                'currency' => 'USD',
            ]);

        $create->assertCreated()
            ->assertJsonPath('data.status', WinnerPayoutStatus::Draft->value)
            ->assertJsonPath('data.amount_cents', 50000)
            ->assertJsonPath('data.currency', 'PEN')
            ->assertJsonPath('data.destination.masked', '****1222')
            ->assertJsonMissingPath('data.destination.payload')
            ->assertJsonMissingPath('data.idempotency_key_hash');

        $payout = WinnerPayout::query()->firstOrFail();
        $this->assertSame($winnerUser->id, $payout->user_id);
        $this->assertSame(2, WinnerPayoutEvent::query()->where('winner_payout_id', $payout->id)->count());

        $this->actingAs($maker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-submit-0001')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', WinnerPayoutStatus::AwaitingApproval->value);

        $this->actingAs($maker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-approve-0001')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/approve")
            ->assertUnprocessable();

        $this->actingAs($checker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-approve-0002')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', WinnerPayoutStatus::Approved->value);

        $this->actingAs($maker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-processing-0001')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/mark-processing")
            ->assertUnprocessable();

        $this->actingAs($checker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-processing-0002')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/mark-processing")
            ->assertOk()
            ->assertJsonPath('data.status', WinnerPayoutStatus::Processing->value);

        Storage::fake('winner_payouts');

        $this->actingAs($checker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-paid-0001')
            ->post("/api/v1/admin/winner-payouts/{$payout->id}/mark-paid", [
                'external_reference' => 'BANK-OP-1234',
                'document' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nreceipt"),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WinnerPayoutStatus::Paid->value)
            ->assertJsonPath('data.external_reference', '****1234')
            ->assertJsonMissingPath('data.documents.0.path');

        $this->assertDatabaseHas('winner_payout_execution_attempts', [
            'winner_payout_id' => $payout->id,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('winner_payout_documents', [
            'payout_id' => $payout->id,
            'document_type' => 'execution_proof',
        ]);
    }

    public function test_create_replay_is_idempotent_and_does_not_duplicate_destination_or_audit(): void
    {
        [, $maker, , $game] = $this->completedGameWithVerifiedClaim();
        $headers = ['Idempotency-Key' => 'winner-payout-create-replay-0001'];
        $payload = ['destination' => ['method' => 'plin', 'phone' => '988777666']];

        $first = $this->actingAs($maker, 'sanctum')
            ->withHeaders($headers)
            ->postJson("/api/v1/admin/games/{$game->id}/winner-payouts", $payload)
            ->assertCreated();

        $second = $this->actingAs($maker, 'sanctum')
            ->withHeaders($headers)
            ->postJson("/api/v1/admin/games/{$game->id}/winner-payouts", $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, WinnerPayout::query()->count());
        $this->assertSame(1, $this->tableCount('winner_payout_destinations'));
        $this->assertSame(2, $this->tableCount('winner_payout_events'));
    }

    public function test_historical_write_endpoint_cannot_bypass_dual_control_for_a_new_claim(): void
    {
        [, $maker, , $game] = $this->completedGameWithVerifiedClaim();

        Storage::fake('winner_payouts');

        $this->actingAs($maker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-legacy-block-0001')
            ->post("/api/v1/admin/games/{$game->id}/winner/payout", [
                'external_reference' => 'OLD-FLOW-001',
                'document' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nproof"),
            ])
            ->assertStatus(410);

        $this->assertSame(0, WinnerPayout::query()->count());
    }

    public function test_winner_cannot_approve_execute_or_confirm_their_own_payout(): void
    {
        [$winnerUser, $maker, $checker, $game] = $this->completedGameWithVerifiedClaim();
        $winnerUser->forceFill(['role' => UserRole::Admin])->save();

        $this->actingAs($maker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-winner-separation-create')
            ->postJson("/api/v1/admin/games/{$game->id}/winner-payouts", [
                'destination' => ['method' => 'cash', 'reference' => 'CASH-001'],
            ])
            ->assertCreated();

        $payout = WinnerPayout::query()->firstOrFail();

        $this->actingAs($maker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-winner-separation-submit')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/submit")
            ->assertOk();

        $this->actingAs($winnerUser, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-winner-separation-approve')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/approve")
            ->assertUnprocessable();

        $this->actingAs($checker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-winner-separation-approve-checker')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/approve")
            ->assertOk();

        $this->actingAs($winnerUser, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-winner-separation-processing')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/mark-processing")
            ->assertUnprocessable();

        $this->actingAs($checker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-winner-separation-processing-checker')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/mark-processing")
            ->assertOk();

        Storage::fake('winner_payouts');

        $this->actingAs($winnerUser, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-winner-separation-paid')
            ->post("/api/v1/admin/winner-payouts/{$payout->id}/mark-paid", [
                'external_reference' => 'CASH-PAID-001',
                'document' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nreceipt"),
            ])
            ->assertUnprocessable();
    }

    public function test_failed_execution_can_be_retried_with_a_new_attempt(): void
    {
        [, $maker, $checker, $game] = $this->completedGameWithVerifiedClaim();

        $this->actingAs($maker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-retry-create')
            ->postJson("/api/v1/admin/games/{$game->id}/winner-payouts", [
                'destination' => ['method' => 'yape', 'phone' => '999111222'],
            ])
            ->assertCreated();

        $payout = WinnerPayout::query()->firstOrFail();

        $this->actingAs($maker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-retry-submit')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/submit")
            ->assertOk();
        $this->actingAs($checker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-retry-approve')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/approve")
            ->assertOk();
        $this->actingAs($checker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-retry-processing-one')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/mark-processing")
            ->assertOk();
        $this->actingAs($checker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-retry-failed')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/mark-failed", [
                'reason_code' => 'destination_rejected',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', WinnerPayoutStatus::Failed->value);

        $this->actingAs($checker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-retry-processing-two')
            ->postJson("/api/v1/admin/winner-payouts/{$payout->id}/mark-processing")
            ->assertOk()
            ->assertJsonPath('data.status', WinnerPayoutStatus::Processing->value);

        $this->assertDatabaseHas('winner_payout_execution_attempts', [
            'winner_payout_id' => $payout->id,
            'attempt_number' => 1,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('winner_payout_execution_attempts', [
            'winner_payout_id' => $payout->id,
            'attempt_number' => 2,
            'status' => 'processing',
        ]);
    }

    public function test_destination_rejects_unexpected_fields(): void
    {
        [, $maker, , $game] = $this->completedGameWithVerifiedClaim();

        $this->actingAs($maker, 'sanctum')
            ->withHeader('Idempotency-Key', 'winner-payout-destination-unexpected-field')
            ->postJson("/api/v1/admin/games/{$game->id}/winner-payouts", [
                'destination' => [
                    'method' => 'yape',
                    'phone' => '999111222',
                    'account_number' => 'must-not-be-accepted',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination']);
    }

    /**
     * @return array{User, User, User, Game}
     */
    private function completedGameWithVerifiedClaim(): array
    {
        $winnerUser = User::factory()->create();
        $maker = User::factory()->admin()->create();
        $checker = User::factory()->admin()->create();
        $now = now();

        $game = Game::create([
            'slug' => 'winner-payout-'.fake()->unique()->lexify('??????'),
            'name' => 'Manual winner payout',
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

        GamePrizeFunding::create([
            'game_id' => $game->id,
            'status' => GamePrizeFundingStatus::Reserved,
            'amount_cents' => $game->prize_cents,
            'currency' => $game->currency,
            'reserved_at' => $now->copy()->subMinutes(10),
        ]);

        $number = GameNumber::create([
            'game_id' => $game->id,
            'number' => 7,
            'status' => GameNumberStatus::Sold,
        ]);
        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => $winnerUser->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => $now->copy()->subHour(),
        ]);
        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => 1,
            'drawn_number' => 7,
            'drawn_at' => $now->copy()->subMinutes(20),
            'strategy' => 'test',
        ]);
        $winner = GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $number->id,
            'user_id' => $winnerUser->id,
            'winning_hits' => 2,
            'won_at' => $now->copy()->subMinutes(15),
        ]);
        WinnerClaim::create([
            'game_winner_id' => $winner->id,
            'winner_user_id' => $winnerUser->id,
            'claim_reference' => 'CLAIM-'.fake()->unique()->lexify('????????????'),
            'status' => WinnerClaimStatus::Verified,
            'verified_at' => $now->copy()->subMinutes(5),
        ]);

        return [$winnerUser, $maker, $checker, $game];
    }

    private function tableCount(string $table): int
    {
        return (int) $this->app['db']->table($table)->count();
    }
}
