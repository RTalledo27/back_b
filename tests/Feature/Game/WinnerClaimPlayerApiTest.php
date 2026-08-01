<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\User;
use App\Modules\RepeatNumberBingo\Application\Actions\CreateWinnerClaimAction;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameDraw;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaimEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerIdentityProfile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WinnerClaimPlayerApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_player_can_list_only_owned_winnings_without_identity_pii(): void
    {
        [$winner, $claim] = $this->winnerWithClaim();
        $other = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($winner, 'sanctum')
            ->getJson('/api/v1/me/winnings')
            ->assertOk()
            ->assertJsonPath('data.0.claim_reference', $claim->claim_reference)
            ->assertJsonMissingPath('data.0.identity_profile')
            ->assertJsonMissingPath('data.0.document_number');

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/v1/me/winnings/'.$claim->game_winner_id)
            ->assertNotFound();
    }

    public function test_claim_requires_verified_email_and_idempotency_key(): void
    {
        [$winner, $claim] = $this->winnerWithClaim();
        $winner->forceFill(['email_verified_at' => null])->save();
        Storage::fake('winner_identity_documents');

        $this->actingAs($winner, 'sanctum')
            ->post('/api/v1/me/winnings/'.$claim->game_winner_id.'/claim', [
                'legal_name' => 'Winner Example',
                'document_type' => 'dni',
                'document_number' => '12345678',
                'accepted_prize_terms' => '1',
                'consented_identity_processing' => '1',
                'identity_front' => UploadedFile::fake()->image('front.jpg'),
            ], [
                'Idempotency-Key' => 'winner-claim-key-unverified',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified');
    }

    public function test_verified_player_submits_claim_with_encrypted_identity_and_private_document(): void
    {
        [$winner, $claim] = $this->winnerWithClaim();
        $winner->forceFill(['email_verified_at' => now()])->save();
        Storage::fake('winner_identity_documents');

        $response = $this->actingAs($winner, 'sanctum')
            ->post('/api/v1/me/winnings/'.$claim->game_winner_id.'/claim', [
                'legal_name' => 'Winner Example',
                'document_type' => 'dni',
                'document_number' => '12345678',
                'accepted_prize_terms' => '1',
                'consented_identity_processing' => '1',
                'identity_front' => UploadedFile::fake()->image('front.jpg'),
            ], [
                'Idempotency-Key' => 'winner-claim-key-0001',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.claim_status', WinnerClaimStatus::IdentityPending->value)
            ->assertJsonPath('data.claim_reference', $claim->claim_reference);

        $claim->refresh();
        $this->assertSame(WinnerClaimStatus::IdentityPending, $claim->status);
        $this->assertNotNull($claim->identity_submitted_at);
        $this->assertNotNull($claim->idempotency_key_hash);
        $this->assertSame(1, WinnerIdentityProfile::query()->where('winner_claim_id', $claim->id)->count());
        $this->assertSame(1, $claim->documents()->count());
        $this->assertSame(1, WinnerClaimEvent::query()
            ->where('winner_claim_id', $claim->id)
            ->where('event_type', WinnerClaimEventType::ClaimSubmitted)
            ->count());

        $storedProfile = DB::table('winner_identity_profiles')->where('winner_claim_id', $claim->id)->first();
        $this->assertNotSame('Winner Example', $storedProfile->legal_name_encrypted);
        $this->assertNotSame('12345678', $storedProfile->document_number_encrypted);
        $this->assertSame('****5678', $storedProfile->document_number_masked);
    }

    public function test_same_idempotency_key_with_different_document_returns_conflict(): void
    {
        [$winner, $claim] = $this->winnerWithClaim();
        $winner->forceFill(['email_verified_at' => now()])->save();
        Storage::fake('winner_identity_documents');
        $headers = ['Idempotency-Key' => 'winner-claim-key-0002'];
        $payload = [
            'legal_name' => 'Winner Example',
            'document_type' => 'dni',
            'document_number' => '12345678',
            'accepted_prize_terms' => '1',
            'consented_identity_processing' => '1',
        ];

        $this->actingAs($winner, 'sanctum')->post(
            '/api/v1/me/winnings/'.$claim->game_winner_id.'/claim',
            [...$payload, 'identity_front' => UploadedFile::fake()->image('front.jpg')],
            $headers,
        )->assertOk();

        $this->actingAs($winner, 'sanctum')->post(
            '/api/v1/me/winnings/'.$claim->game_winner_id.'/claim',
            [...$payload, 'identity_front' => UploadedFile::fake()->image('different.jpg', 101, 101)],
            $headers,
        )->assertStatus(409);
    }

    /** @return array{User, WinnerClaim} */
    private function winnerWithClaim(): array
    {
        $winnerUser = User::factory()->create();
        $game = Game::create([
            'slug' => 'claim-'.fake()->unique()->lexify('??????'),
            'name' => 'Claim game',
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
            'confirmed_at' => now()->subMinute(),
        ]);
        $draw = GameDraw::create([
            'game_id' => $game->id,
            'game_number_id' => $number->id,
            'sequence' => 1,
            'drawn_number' => 7,
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

        return [$winnerUser, $claim];
    }
}
