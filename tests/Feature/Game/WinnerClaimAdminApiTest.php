<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\CreateWinnerClaimAction;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaimEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerIdentityDocument;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerIdentityProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WinnerClaimAdminApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_player_cannot_review_or_list_winner_claims(): void
    {
        $player = User::factory()->create();

        $this->actingAs($player, 'sanctum')
            ->getJson('/api/v1/admin/winner-claims')
            ->assertForbidden();
    }

    public function test_admin_can_verify_claim_and_replay_does_not_add_event(): void
    {
        [$winner, $claim] = $this->claimWithIdentity();
        $admin = User::factory()->admin()->create();
        $headers = ['Idempotency-Key' => 'winner-review-key-0001'];

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/winner-claims/'.$claim->id.'/verify', [], $headers);

        $response->assertOk()
            ->assertJsonPath('data.claim.status', WinnerClaimStatus::Verified->value)
            ->assertJsonPath('data.identity_profile.document_number_masked', '****5678')
            ->assertJsonMissingPath('data.identity_profile.document_number_encrypted');

        $this->assertSame(WinnerClaimStatus::Verified, $claim->refresh()->status);
        $eventCount = WinnerClaimEvent::query()
            ->where('winner_claim_id', $claim->id)
            ->where('event_type', 'identity_verified')
            ->count();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/winner-claims/'.$claim->id.'/verify', [], $headers)
            ->assertOk();

        $this->assertSame($eventCount, WinnerClaimEvent::query()
            ->where('winner_claim_id', $claim->id)
            ->where('event_type', 'identity_verified')
            ->count());
        $this->assertNotSame($winner->id, $admin->id);
    }

    public function test_admin_can_reject_with_reason_code_and_download_is_private(): void
    {
        [, $claim] = $this->claimWithIdentity();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/winner-claims/'.$claim->id.'/reject', [
                'reason_code' => 'document_unreadable',
            ], [
                'Idempotency-Key' => 'winner-review-key-0002',
            ])
            ->assertOk()
            ->assertJsonPath('data.claim.status', WinnerClaimStatus::Rejected->value);

        $document = $claim->documents()->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/admin/winner-claims/'.$claim->id.'/documents/'.$document->id.'/download')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_winner_cannot_review_own_claim(): void
    {
        [$winner, $claim] = $this->claimWithIdentity();

        $this->actingAs($winner, 'sanctum')
            ->postJson('/api/v1/admin/winner-claims/'.$claim->id.'/verify', [], [
                'Idempotency-Key' => 'winner-review-key-0003',
            ])
            ->assertForbidden();
    }

    /** @return array{User, WinnerClaim} */
    private function claimWithIdentity(): array
    {
        Storage::fake('winner_identity_documents');
        $winnerUser = User::factory()->create(['email_verified_at' => now()]);
        $game = Game::create([
            'slug' => 'admin-claim-'.fake()->unique()->lexify('??????'),
            'name' => 'Admin claim game',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 2,
            'ticket_price_cents' => 500,
            'prize_cents' => 2000,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => false,
            'status' => GameStatus::Completed,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
        $number = GameNumber::create(['game_id' => $game->id, 'number' => 8, 'status' => GameNumberStatus::Sold]);
        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'user_id' => $winnerUser->id,
            'status' => EntryStatus::Winner,
            'confirmed_at' => now()->subMinute(),
        ]);
        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => 1,
            'drawn_number' => 8,
            'drawn_at' => now()->subMinute(),
            'strategy' => 'test',
        ]);
        $winner = GameWinner::create([
            'game_id' => $game->id,
            'game_entry_id' => $entry->id,
            'game_draw_id' => $draw->id,
            'game_number_id' => $number->id,
            'user_id' => $winnerUser->id,
            'winning_hits' => 2,
            'won_at' => now(),
        ]);
        $claim = DB::transaction(fn (): WinnerClaim => $this->app
            ->make(CreateWinnerClaimAction::class)
            ->executeWithinTransaction($winner->id));

        $now = now();
        $claim->transitionTo(WinnerClaimStatus::IdentityPending);
        $claim->claimed_at = $now;
        $claim->identity_submitted_at = $now;
        $claim->save();
        WinnerIdentityProfile::create([
            'winner_claim_id' => $claim->id,
            'document_type' => 'dni',
            'legal_name_encrypted' => 'Winner Example',
            'document_number_encrypted' => '12345678',
            'document_number_masked' => '****5678',
            'accepted_prize_terms_at' => $now,
            'consented_identity_processing_at' => $now,
        ]);
        $path = 'claims/'.$winner->id.'/document.jpg';
        Storage::disk('winner_identity_documents')->put($path, 'identity');
        WinnerIdentityDocument::create([
            'winner_claim_id' => $claim->id,
            'document_type' => 'identity_front',
            'disk' => 'winner_identity_documents',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => 8,
            'sha256' => hash('sha256', 'identity'),
            'uploaded_by_user_id' => $winnerUser->id,
        ]);

        return [$winnerUser, $claim->refresh()];
    }
}
